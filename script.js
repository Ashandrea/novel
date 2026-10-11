(() => {
  const API_URL = "api.php";
  const coverColors = ["#758c76", "#bd856d", "#71879a", "#a39370", "#897b91", "#65877f"];
  const $ = (selector) => document.querySelector(selector);
  const loginPage = $("#loginPage");
  const appPage = $("#appPage");
  const loginForm = $("#loginForm");
  const loginError = $("#loginError");
  const registerForm = $("#registerForm");
  const registerError = $("#registerError");
  const bookModal = $("#bookModal");
  const bookForm = $("#bookForm");
  const accountModal = $("#accountModal");
  const accountForm = $("#accountForm");
  const readerModal = $("#readerModal");
  const toast = $("#toast");

  let books = [];
  let accounts = [];
  let currentUser = null;
  let csrfToken = "";
  let booksError = "";
  let toastTimer;
  let isRegisterMode = false;
  let readerChapters = [];
  let readerChapterIndex = 0;
  let chapterNumberOffset = 0;
  let chapterMinimum = 1;

  class ApiError extends Error {
    constructor(message, status) {
      super(message);
      this.status = status;
    }
  }

  async function request(action, { method = "GET", body, csrf = false, query = {} } = {}) {
    const headers = { Accept: "application/json" };
    if (body !== undefined) headers["Content-Type"] = "application/json";
    if (csrf) headers["X-CSRF-Token"] = csrfToken;
    let response;
    try {
      const parameters = new URLSearchParams({ action, ...query });
      response = await fetch(`${API_URL}?${parameters}`, {
        method,
        credentials: "same-origin",
        headers,
        body: body === undefined ? undefined : JSON.stringify(body)
      });
    } catch (error) {
      throw new ApiError("Server tidak dapat dijangkau. Jalankan Laragon dan buka aplikasi melalui localhost.", 0);
    }
    let result;
    try {
      result = await response.json();
    } catch (error) {
      throw new ApiError("Server mengirim respons yang tidak dapat dibaca. Periksa konfigurasi PHP.", response.status);
    }
    if (!response.ok) {
      throw new ApiError(result.error || "Permintaan tidak berhasil.", response.status);
    }
    return result;
  }

  function escapeHtml(value) {
    return String(value ?? "").replace(/[&<>"']/g, (character) => ({
      "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;"
    })[character]);
  }

  function showToast(message) {
    toast.textContent = message;
    toast.classList.add("visible");
    window.clearTimeout(toastTimer);
    toastTimer = window.setTimeout(() => toast.classList.remove("visible"), 2700);
  }

  function showError(target, message) {
    target.textContent = message;
  }

  function handleRequestError(error) {
    if (!(error instanceof ApiError)) {
      console.error("Unexpected application error.", error);
      showToast("Terjadi kesalahan yang tidak terduga.");
      return;
    }
    if (error.status === 401) {
      currentUser = null;
      csrfToken = "";
      appPage.hidden = true;
      loginPage.hidden = false;
      loginError.textContent = error.message;
      return;
    }
    if (error.status === 0 || error.status >= 500) console.error(error.message);
    showToast(error.message);
  }

  function setSignedIn(user) {
    currentUser = user;
    const displayName = user.username.charAt(0).toUpperCase() + user.username.slice(1);
    loginPage.hidden = true;
    appPage.hidden = false;
    $("#profileName").textContent = displayName;
    $("#welcomeName").textContent = `${displayName}.`;
    $("#welcomeDescription").textContent = user.role === "admin"
      ? "Kelola novel dan akun, termasuk novel yang ditambahkan pengguna."
      : "Temukan cerita menarik dan tambahkan novel ke koleksi bersama.";
    $("#heroDescription").textContent = user.role === "admin"
      ? "Tambahkan novel, perbarui informasi, dan hapus novel yang tidak diperlukan dari koleksi."
      : "Telusuri koleksi, cari cerita berdasarkan judul atau penulis, dan tambahkan novel untuk dibagikan.";
    $("#collectionDescription").textContent = user.role === "admin"
      ? "Kelola koleksi novel semua pengguna, cari cerita, dan hapus novel yang tidak diperlukan."
      : "Jelajahi koleksi perpustakaan atau tambahkan novel untuk dibagikan.";
    $("#accountsNav").hidden = user.role !== "admin";
    document.querySelectorAll(".admin-only").forEach((element) => {
      element.hidden = user.role !== "admin";
    });
    document.querySelectorAll(".avatar, .topbar-avatar").forEach((avatar) => {
      avatar.textContent = displayName.charAt(0).toUpperCase();
    });
    document.querySelector(".profile-info span").textContent = user.role === "admin" ? "Administrator" : "Pengunjung";
  }

  async function loadBooks() {
    try {
      const result = await request("novels");
      books = result.novels;
      booksError = "";
      render();
    } catch (error) {
      booksError = error.message;
      render();
      throw error;
    }
  }

  async function loadAccounts() {
    const result = await request("accounts");
    accounts = result.accounts;
    renderAccounts();
  }

  loginForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    loginError.textContent = "";
    const button = loginForm.querySelector('button[type="submit"]');
    button.disabled = true;
    try {
      const result = await request("login", {
        method: "POST",
        body: { username: $("#username").value.trim(), password: $("#password").value }
      });
      csrfToken = result.csrf;
      setSignedIn(result.user);
      await loadBooks();
      loginForm.reset();
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) {
        loginError.textContent = error.message;
      } else {
        loginError.textContent = error.message || "Login tidak berhasil.";
        if (error.status === 0 || error.status >= 500) console.error(error.message);
      }
    } finally {
      button.disabled = false;
    }
  });

  $("#toggleAuthMode").addEventListener("click", () => {
    isRegisterMode = !isRegisterMode;
    loginForm.hidden = isRegisterMode;
    registerForm.hidden = !isRegisterMode;
    $("#loginSwitchText").textContent = isRegisterMode ? "Sudah punya akun?" : "Belum punya akun?";
    $("#toggleAuthMode").textContent = isRegisterMode ? "Masuk" : "Daftar sekarang";
    loginError.textContent = "";
    registerError.textContent = "";
    (isRegisterMode ? $("#registerUsername") : $("#username")).focus();
  });

  registerForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    registerError.textContent = "";
    const username = $("#registerUsername").value.trim();
    const password = $("#registerPassword").value;
    if (password !== $("#registerPasswordConfirm").value) {
      registerError.textContent = "Konfirmasi kata sandi tidak sesuai.";
      return;
    }
    const button = registerForm.querySelector('button[type="submit"]');
    button.disabled = true;
    try {
      const result = await request("register", {
        method: "POST",
        body: { username, password }
      });
      csrfToken = result.csrf;
      registerForm.reset();
      loginForm.reset();
      setSignedIn(result.user);
      await loadBooks();
    } catch (error) {
      if (currentUser) {
        handleRequestError(error);
      } else {
        registerError.textContent = error.message || "Pendaftaran tidak berhasil.";
        if (error.status === 0 || error.status >= 500) console.error(error.message);
      }
    } finally {
      button.disabled = false;
    }
  });

  $("#logoutButton").addEventListener("click", async () => {
    try {
      await request("logout", { method: "POST", csrf: true });
      currentUser = null;
      csrfToken = "";
      books = [];
      accounts = [];
      appPage.hidden = true;
      loginPage.hidden = false;
      loginForm.reset();
      registerForm.reset();
      loginForm.hidden = false;
      registerForm.hidden = true;
      isRegisterMode = false;
      $("#loginSwitchText").textContent = "Belum punya akun?";
      $("#toggleAuthMode").textContent = "Daftar sekarang";
      loginError.textContent = "";
      registerError.textContent = "";
      switchView("home");
    } catch (error) {
      handleRequestError(error);
    }
  });

  function switchView(view) {
    const isCollection = view === "collection";
    const isAccounts = view === "accounts";
    if (isAccounts && currentUser?.role !== "admin") {
      showToast("Halaman ini hanya tersedia untuk admin.");
      return;
    }
    $("#homeView").hidden = isCollection || isAccounts;
    $("#collectionView").hidden = !isCollection;
    $("#accountsView").hidden = !isAccounts;
    $("#breadcrumbPage").textContent = isCollection ? "Koleksi novel" : isAccounts ? "Kelola akun" : "Beranda";
    document.querySelectorAll(".nav-link").forEach((link) => {
      link.classList.toggle("active", link.dataset.view === view);
    });
    if (isCollection) renderCollection();
    if (isAccounts) loadAccounts().catch(handleRequestError);
    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  document.querySelectorAll("[data-view]").forEach((element) => {
    element.addEventListener("click", () => switchView(element.dataset.view));
  });

  function openBookModal(book) {
    if (!currentUser || (book && !canManageBook(book))) return;
    bookForm.reset();
    $("#bookError").textContent = "";
    $("#chapterFields").replaceChildren();
    $("#bookId").value = book ? book.id : "";
    $("#modalTitle").textContent = book ? "Ubah detail novel" : "Novel baru";
    $("#chaptersEditor").hidden = false;
    chapterNumberOffset = book ? Number(book.chapterCount) : 0;
    chapterMinimum = book ? 0 : 1;
    if (book) {
      $("#titleInput").value = book.title;
      $("#authorInput").value = book.author;
      $("#genreInput").value = book.genre || "";
      $("#yearInput").value = book.year || "";
      $("#statusInput").value = book.status || "Belum dibaca";
      $("#descriptionInput").value = book.description || "";
    } else {
      addChapterField();
    }
    bookModal.hidden = false;
    $("#titleInput").focus();
  }

  function refreshChapterFields() {
    const fields = Array.from($("#chapterFields").querySelectorAll("[data-chapter-field]"));
    fields.forEach((field, index) => {
      field.querySelector("[data-chapter-number]").textContent = `Bab ${chapterNumberOffset + index + 1}`;
      field.querySelector("[data-remove-chapter]").hidden = fields.length <= chapterMinimum;
    });
  }

  function addChapterField() {
    const index = $("#chapterFields").querySelectorAll("[data-chapter-field]").length + 1;
    const field = document.createElement("section");
    field.className = "chapter-field";
    field.dataset.chapterField = "";
    field.innerHTML = `
      <div class="chapter-field-heading"><strong data-chapter-number></strong><button class="text-button" data-remove-chapter type="button">Hapus bab</button></div>
      <label>Judul bab <span>*</span><input data-chapter-title type="text" maxlength="120" placeholder="Misalnya: Pertemuan pertama" required></label>
      <label>Isi cerita <span>*</span><textarea data-chapter-content rows="8" maxlength="100000" placeholder="Tulis isi bab di sini..." required></textarea></label>`;
    field.querySelector("[data-remove-chapter]").addEventListener("click", () => {
      field.remove();
      refreshChapterFields();
    });
    $("#chapterFields").append(field);
    field.querySelector("[data-chapter-title]").value = `Bab ${chapterNumberOffset + index}`;
    refreshChapterFields();
  }

  $("#addChapterButton").addEventListener("click", addChapterField);

  function closeBookModal() {
    bookModal.hidden = true;
  }

  document.querySelectorAll("[data-open-form]").forEach((button) => {
    button.addEventListener("click", () => openBookModal());
  });
  $("#closeModal").addEventListener("click", closeBookModal);
  $("#cancelModal").addEventListener("click", closeBookModal);
  bookModal.addEventListener("click", (event) => {
    if (event.target === bookModal) closeBookModal();
  });

  bookForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    const id = $("#bookId").value;
    const book = id ? books.find((item) => item.id === id) : null;
    if (!currentUser || (id && (!book || !canManageBook(book)))) {
      $("#bookError").textContent = "Kamu tidak memiliki izin untuk mengubah novel ini.";
      return;
    }
    const data = {
      title: $("#titleInput").value.trim(),
      author: $("#authorInput").value.trim(),
      genre: $("#genreInput").value.trim(),
      year: $("#yearInput").value,
      status: $("#statusInput").value,
      description: $("#descriptionInput").value.trim()
    };
    const chapters = Array.from($("#chapterFields").querySelectorAll("[data-chapter-field]")).map((field) => ({
      title: field.querySelector("[data-chapter-title]").value.trim(),
      content: field.querySelector("[data-chapter-content]").value
    }));
    if (!data.title || !data.author) {
      $("#bookError").textContent = "Judul novel dan nama penulis wajib diisi.";
      return;
    }
    if ((!id && chapters.length < 1) || chapters.some((chapter) => !chapter.title || !chapter.content.trim())) {
      $("#bookError").textContent = "Setiap bab harus memiliki judul dan isi cerita.";
      return;
    }
    const button = bookForm.querySelector('button[type="submit"]');
    button.disabled = true;
    try {
      const result = await request("novels", {
        method: id ? "PUT" : "POST",
        body: id ? { ...data, id, chapters } : { ...data, chapters },
        csrf: true
      });
      closeBookModal();
      await loadBooks();
      showToast(result.message);
    } catch (error) {
      $("#bookError").textContent = error.message;
      if (error.status === 0 || error.status >= 500) console.error(error.message);
    } finally {
      button.disabled = false;
    }
  });

  async function removeBook(id) {
    const book = books.find((item) => item.id === id);
    if (!book || !canManageBook(book)) return;
    if (!window.confirm(`Hapus "${book.title}" dari koleksi beserta seluruh babnya?`)) return;
    try {
      const result = await request("novels", { method: "DELETE", body: { id }, csrf: true });
      await loadBooks();
      showToast(result.message);
    } catch (error) {
      handleRequestError(error);
    }
  }

  function canManageBook(book) {
    return currentUser?.role === "admin"
      || (String(book.ownerUserId) === String(currentUser?.id) && book.ownerUserId !== null);
  }

  function bookCard(book, index) {
    const statusClass = book.status === "Selesai" ? "finished" : book.status === "Sedang dibaca" ? "reading" : "";
    const title = escapeHtml(book.title);
    const author = escapeHtml(book.author);
    const genre = escapeHtml(book.genre || "Novel");
    const status = escapeHtml(book.status || "Belum dibaca");
    const year = escapeHtml(book.year || "—");
    const coverColor = coverColors[index % coverColors.length];
    const actions = canManageBook(book)
      ? `<button type="button" data-edit="${escapeHtml(book.id)}">Ubah</button><button type="button" data-delete="${escapeHtml(book.id)}">Hapus</button>`
      : "";
    const readAction = Number(book.chapterCount) > 0
      ? `<button type="button" data-read="${escapeHtml(book.id)}">Baca cerita · ${escapeHtml(book.chapterCount)} bab</button>`
      : `<button type="button" data-read="${escapeHtml(book.id)}">Baca novel</button>`;
    const owner = currentUser?.role === "admin" && book.ownerUsername
      ? `<p class="book-owner">Ditambahkan oleh ${escapeHtml(book.ownerUsername)}</p>`
      : "";
    const cover = `<div class="book-cover" style="--cover:${coverColor}"><span>${title}</span><small>${genre}</small></div>`;
    return `<article class="book-card" data-open-book="${escapeHtml(book.id)}" tabindex="0" role="button" aria-label="Buka novel ${title}">
      <div class="book-card-top">
        ${Number(book.chapterCount) > 0
          ? `<button class="book-open-button" type="button" data-read="${escapeHtml(book.id)}" aria-label="Baca ${title}">${cover}</button>`
          : cover}
        <span class="status-pill ${statusClass}">${status}</span>
      </div>
      <h3 title="${title}">${Number(book.chapterCount) > 0
        ? `<button class="book-title-button" type="button" data-read="${escapeHtml(book.id)}">${title}</button>`
        : title}</h3>
      <p class="book-author">${author}</p>
      ${owner}
      ${book.description ? `<p class="book-author">${escapeHtml(book.description)}</p>` : ""}
      <div class="book-meta"><span>${genre} · ${year}</span><span class="card-actions">${readAction}${actions}</span></div>
    </article>`;
  }

  function renderCards(container, list) {
    if (booksError) {
      container.innerHTML = `<div class="empty-state"><strong>Koleksi tidak dapat dimuat.</strong><p>${escapeHtml(booksError)}</p></div>`;
      return;
    }
    if (!list.length) {
      const message = currentUser?.role === "admin"
        ? '<strong>Rakmu masih menunggu cerita.</strong><p>Tambahkan novel pertama untuk mulai mengisi koleksi.</p>'
        : '<strong>Belum ada novel di koleksi.</strong><p>Silakan cek kembali nanti untuk cerita baru.</p>';
      container.innerHTML = `<div class="empty-state">${message}</div>`;
      return;
    }
    container.innerHTML = list.map(bookCard).join("");
    container.querySelectorAll("[data-edit]").forEach((button) => {
      button.addEventListener("click", () => {
        const book = books.find((item) => item.id === button.dataset.edit);
        if (book && canManageBook(book)) openBookModal(book);
      });
    });
    container.querySelectorAll("[data-delete]").forEach((button) => {
      button.addEventListener("click", () => removeBook(button.dataset.delete));
    });
    container.querySelectorAll("[data-read]").forEach((button) => {
      button.addEventListener("click", () => openStory(button.dataset.read));
    });
    container.querySelectorAll("[data-open-book]").forEach((card) => {
      card.addEventListener("click", (event) => {
        if (event.target.closest("button, a, input, select, textarea")) return;
        openStory(card.dataset.openBook);
      });
      card.addEventListener("keydown", (event) => {
        if (event.target !== card || (event.key !== "Enter" && event.key !== " ")) return;
        event.preventDefault();
        openStory(card.dataset.openBook);
      });
    });
  }

  function renderReaderChapter() {
    const chapter = readerChapters[readerChapterIndex];
    if (!chapter) return;
    $("#readerChapterPosition").textContent = `Bab ${chapter.chapterNumber}`;
    $("#readerChapterCount").textContent = `${readerChapterIndex + 1} dari ${readerChapters.length}`;
    $("#readerChapterTitle").textContent = chapter.title;
    $("#readerChapterContent").textContent = chapter.content;
    $("#previousChapterButton").disabled = readerChapterIndex === 0;
    $("#nextChapterButton").disabled = readerChapterIndex === readerChapters.length - 1;
  }

  async function openStory(id) {
    try {
      const result = await request("story", { query: { novelId: id } });
      readerChapters = result.chapters;
      readerChapterIndex = 0;
      $("#readerBookTitle").textContent = result.novel.title;
      $("#readerBookAuthor").textContent = result.novel.author;
      if (readerChapters.length) {
        $("#readerProgress").hidden = false;
        $("#readerChapterTitle").hidden = false;
        $("#readerChapterContent").textContent = "";
        $("#readerNotice").hidden = true;
        renderReaderChapter();
      } else {
        $("#readerProgress").hidden = true;
        $("#readerChapterTitle").hidden = true;
        $("#readerChapterContent").textContent = result.novel.description
          || "Belum ada ringkasan untuk novel ini.";
        $("#readerNotice").textContent = "Isi cerita per bab belum ditambahkan. Ini hanya ringkasan novel.";
        $("#readerNotice").hidden = false;
      }
      $("#readerActions").hidden = readerChapters.length === 0;
      readerModal.hidden = false;
    } catch (error) {
      handleRequestError(error);
    }
  }

  function closeReader() {
    readerModal.hidden = true;
    readerChapters = [];
    readerChapterIndex = 0;
  }

  $("#closeReaderModal").addEventListener("click", closeReader);
  readerModal.addEventListener("click", (event) => {
    if (event.target === readerModal) closeReader();
  });
  $("#previousChapterButton").addEventListener("click", () => {
    if (readerChapterIndex > 0) {
      readerChapterIndex -= 1;
      renderReaderChapter();
    }
  });
  $("#nextChapterButton").addEventListener("click", () => {
    if (readerChapterIndex < readerChapters.length - 1) {
      readerChapterIndex += 1;
      renderReaderChapter();
    }
  });

  function renderCollection() {
    const query = $("#searchInput").value.trim().toLocaleLowerCase("id");
    const status = $("#statusFilter").value;
    const filtered = books.filter((book) => {
      const matchesSearch = `${book.title} ${book.author} ${book.genre}`.toLocaleLowerCase("id").includes(query);
      return matchesSearch && (!status || book.status === status);
    });
    $("#resultCount").textContent = `${filtered.length} novel`;
    renderCards($("#collectionBooks"), filtered);
  }

  function render() {
    $("#totalBooks").textContent = booksError ? "—" : books.length;
    $("#readingBooks").textContent = booksError ? "—" : books.filter((book) => book.status === "Sedang dibaca").length;
    $("#finishedBooks").textContent = booksError ? "—" : books.filter((book) => book.status === "Selesai").length;
    renderCards($("#recentBooks"), books.slice(0, 3));
    renderCollection();
  }

  function openAccountModal(account) {
    if (currentUser?.role !== "admin") return;
    accountForm.reset();
    $("#accountError").textContent = "";
    $("#accountId").value = account ? account.id : "";
    $("#accountModalTitle").textContent = account ? "Ubah akun" : "Akun baru";
    $("#accountUsername").value = account ? account.username : "";
    $("#accountPassword").required = !account;
    $("#passwordRequired").hidden = Boolean(account);
    $("#passwordHint").textContent = account ? "Kosongkan jika tidak ingin mengganti kata sandi." : "Minimal 8 karakter.";
    $("#accountRole").value = account ? account.role : "user";
    $("#accountRole").disabled = Boolean(account && String(account.id) === String(currentUser.id));
    accountModal.hidden = false;
    $("#accountUsername").focus();
  }

  function closeAccountModal() {
    accountModal.hidden = true;
  }

  $("#addAccountButton").addEventListener("click", () => openAccountModal());
  $("#closeAccountModal").addEventListener("click", closeAccountModal);
  $("#cancelAccountModal").addEventListener("click", closeAccountModal);
  accountModal.addEventListener("click", (event) => {
    if (event.target === accountModal) closeAccountModal();
  });

  accountForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (currentUser?.role !== "admin") return;
    const id = $("#accountId").value;
    const body = {
      username: $("#accountUsername").value.trim(),
      password: $("#accountPassword").value,
      role: $("#accountRole").value
    };
    if (!body.username || (!id && body.password.length < 8) || (body.password && body.password.length < 8)) {
      $("#accountError").textContent = !body.username
        ? "Nama pengguna wajib diisi."
        : "Kata sandi harus minimal 8 karakter.";
      return;
    }
    const button = accountForm.querySelector('button[type="submit"]');
    button.disabled = true;
    try {
      const result = await request("accounts", {
        method: id ? "PUT" : "POST",
        body: id ? { ...body, id } : body,
        csrf: true
      });
      closeAccountModal();
      await loadAccounts();
      showToast(result.message);
    } catch (error) {
      $("#accountError").textContent = error.message;
      if (error.status === 0 || error.status >= 500) console.error(error.message);
    } finally {
      button.disabled = false;
    }
  });

  async function removeAccount(id) {
    const account = accounts.find((item) => String(item.id) === String(id));
    if (!account || String(account.id) === String(currentUser.id)) return;
    if (!window.confirm(`Hapus akun "${account.username}"?`)) return;
    try {
      const result = await request("accounts", { method: "DELETE", body: { id }, csrf: true });
      await loadAccounts();
      showToast(result.message);
    } catch (error) {
      handleRequestError(error);
    }
  }

  function renderAccounts() {
    const tbody = $("#accountsTableBody");
    const empty = $("#accountsEmpty");
    empty.hidden = accounts.length > 0;
    tbody.innerHTML = accounts.map((account) => {
      const isCurrent = String(account.id) === String(currentUser.id);
      const created = new Date(account.createdAt).toLocaleDateString("id-ID", {
        day: "numeric", month: "short", year: "numeric"
      });
      return `<tr>
        <td>${escapeHtml(account.username)}${isCurrent ? " (kamu)" : ""}</td>
        <td><span class="role-badge ${account.role === "admin" ? "admin" : ""}">${account.role === "admin" ? "Admin" : "User"}</span></td>
        <td>${escapeHtml(created)}</td>
        <td><span class="account-actions"><button type="button" data-account-edit="${escapeHtml(account.id)}">Ubah</button>${isCurrent ? "" : `<button type="button" data-account-delete="${escapeHtml(account.id)}">Hapus</button>`}</span></td>
      </tr>`;
    }).join("");
    tbody.querySelectorAll("[data-account-edit]").forEach((button) => {
      button.addEventListener("click", () => {
        const account = accounts.find((item) => String(item.id) === button.dataset.accountEdit);
        if (account) openAccountModal(account);
      });
    });
    tbody.querySelectorAll("[data-account-delete]").forEach((button) => {
      button.addEventListener("click", () => removeAccount(button.dataset.accountDelete));
    });
  }

  $("#searchInput").addEventListener("input", renderCollection);
  $("#statusFilter").addEventListener("change", renderCollection);
  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") {
      if (!bookModal.hidden) closeBookModal();
      if (!accountModal.hidden) closeAccountModal();
      if (!readerModal.hidden) closeReader();
    }
  });

  async function initialize() {
    try {
      const result = await request("me");
      csrfToken = result.csrf || "";
      if (result.user) {
        setSignedIn(result.user);
        await loadBooks();
      }
    } catch (error) {
      if (error.status === 0 || error.status >= 500) {
        loginError.textContent = error.message;
        console.error(error.message);
      }
    }
  }

  initialize();
})();
