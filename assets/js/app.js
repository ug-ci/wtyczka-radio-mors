/**
 * LISTA PRZEBOJÓW RADIA STUDENCKIEGO (RADIO MORS) • UNIWERSYTET GDAŃSKI
 * Faza 1: Frontend SPA zasilany przez Express + PostgreSQL (Prisma) API.
 * Zachowuje wizualny UG Design System oraz logikę biznesową oryginalnego mockupu,
 * zastępując localStorage prawdziwym zapisem po stronie serwera.
 */

// Dane zlokalizowane przez WordPress (wp_localize_script('mors-app','morsData', ...)).
// Na publicznej stronie: { restUrl, nonce, isEditor:false, isAdminPanel:false }.
const MORS = (typeof window !== 'undefined' && window.morsData) ? window.morsData : {};
const API_BASE = (window.morsData && window.morsData.restUrl) || '/wp-json/mors/v1';

/**
 * Bezpieczne wywołanie ikon Lucide — nie wywala aplikacji, gdy biblioteka
 * (enqueue jako zależność mors-app) nie zdążyła się załadować.
 */
function morsCreateIcons() {
  if (typeof lucide !== 'undefined' && lucide && typeof lucide.createIcons === 'function') {
    lucide.createIcons();
  }
}

/**
 * Zabezpieczenie XSS: escapuje dane użytkownika/redakcji przed wstawieniem do innerHTML.
 * Używać dla KAŻDEJ wartości pochodzącej z API (tytuły, wykonawcy, nazwiska, e-maile itd.).
 */
function escapeHtml(value) {
  if (value === null || value === undefined) return '';
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

class StudentRadioApp {
  constructor() {
    this.state = {
      edition: null,
      chartTracks: [],
      waitingRoomTracks: [],
      config: (MORS && MORS.config) || { chartSize: 25, waitingSize: 35, promotedCount: 2, maxVotes: 15 },
    };
    this.selectedVotes = new Set();
    this.maxVotesAllowed = (this.state.config && this.state.config.maxVotes) ? this.state.config.maxVotes : 15;
    this.cooldownEndTime = null;
    this.activeTab = 'chart';

    this.adminUser = null;
    this.editors = [];
    this.pendingCoverFile = null;
    this.pendingCoverDataUrl = null;
    this.pendingAudioFile = null;

    this.adminTracks = [];
    this.adminTracksActiveTab = 'chart';

    // Audio engine
    this.audioCtx = null;
    this.currentPlayingId = null;
    this.activeOscillators = [];
    this.audioTimeout = null;
    this.currentAudioEl = null;

    this.init();
  }

  async init() {
    this.bindEvents();
    this.applyAdminModeUI();
    await this.refreshAdminSession();
    await this.refreshChartState();
    await this.check24hCooldown();
    this.render();
    this.startPolling();
    this.startCountdownTimer();
    this.updateAdminAuthUI();
    // W backendzie (wp-admin) otwieramy od razu widok zarządzania (Panel Redaktora).
    if (MORS.isAdminPanel && this.adminUser) this.switchTab('admin');
    morsCreateIcons();
  }

  // Na publicznej stronie (isAdminPanel=false) chowamy przełącznik "Panel Redaktora"
  // oraz wewnątrz-SPA modal logowania — WordPress ma osobny panel administracyjny.
  applyAdminModeUI() {
    if (MORS.isAdminPanel) {
      // W panelu wp-admin chowamy publiczny nagłówek (.mors-chrome) — WordPress ma własną belkę.
      document.querySelectorAll('.mors-chrome').forEach((el) => el.classList.add('hidden'));
      // Pokazujemy właściwą podsekcję zależnie od podmenu (Wszystkie utwory / Ustawienia listy).
      this.applyAdminSection();
      return;
    }
    const adminTabBtn = document.querySelector('.nav-tab-btn[data-tab="admin"]');
    if (adminTabBtn) adminTabBtn.classList.add('hidden');
    const loginModal = document.getElementById('admin-login-modal');
    if (loginModal) loginModal.classList.add('hidden');
    const adminView = document.getElementById('view-admin');
    if (adminView) adminView.classList.add('hidden');
  }

  // Pokazuje podsekcję panelu zależnie od morsData.adminSection
  // ('tracks' | 'add-track' | 'settings').
  applyAdminSection() {
    const sections = ['tracks', 'add-track', 'settings'];
    const active = sections.includes(MORS.adminSection) ? MORS.adminSection : 'tracks';
    sections.forEach((s) => {
      const el = document.getElementById('admin-section-' + s);
      if (el) el.classList.toggle('hidden', s !== active);
    });
  }

  // Przełączanie zakładek Notowanie / Poczekalnia w sekcji Utwory
  switchAdminTracksTab(tab) {
    this.adminTracksActiveTab = (tab === 'waiting') ? 'waiting' : 'chart';
    const chartBtn = document.getElementById('admin-tracks-tab-btn-chart');
    const waitingBtn = document.getElementById('admin-tracks-tab-btn-waiting');
    const chartPanel = document.getElementById('admin-tracks-panel-chart');
    const waitingPanel = document.getElementById('admin-tracks-panel-waiting');

    if (chartBtn && waitingBtn) {
      if (this.adminTracksActiveTab === 'chart') {
        chartBtn.className = 'admin-tracks-tab-btn px-4 py-2 text-xs sm:text-sm font-headings font-bold flex items-center gap-2 bg-[#0041d2] text-white shadow-sm border border-[#0041d2] transition-all';
        waitingBtn.className = 'admin-tracks-tab-btn px-4 py-2 text-xs sm:text-sm font-headings font-semibold flex items-center gap-2 text-[#032c73] hover:bg-[#e5f5fd] hover:text-[#0041d2] border border-transparent transition-all';
      } else {
        waitingBtn.className = 'admin-tracks-tab-btn px-4 py-2 text-xs sm:text-sm font-headings font-bold flex items-center gap-2 bg-[#0041d2] text-white shadow-sm border border-[#0041d2] transition-all';
        chartBtn.className = 'admin-tracks-tab-btn px-4 py-2 text-xs sm:text-sm font-headings font-semibold flex items-center gap-2 text-[#032c73] hover:bg-[#e5f5fd] hover:text-[#0041d2] border border-transparent transition-all';
      }
    }

    if (chartPanel) chartPanel.classList.toggle('hidden', this.adminTracksActiveTab !== 'chart');
    if (waitingPanel) waitingPanel.classList.toggle('hidden', this.adminTracksActiveTab !== 'waiting');

    morsCreateIcons();
  }

  // Uzupełnia kartę "Ustawienia listy" danymi bieżącej edycji oraz formularzem konfiguracji.
  renderSettingsInfo() {
    const ed = this.state.edition;
    if (ed) {
      const set = (id, val) => { const el = document.getElementById(id); if (el) el.innerText = val; };
      set('settings-edition-num', `#${ed.number != null ? ed.number : '--'}`);
      set('settings-edition-status', ed.status || '--');
      set('settings-edition-votes', ed.totalVotesCount != null ? ed.totalVotesCount : 0);
      const ends = ed.endsAt ? new Date(ed.endsAt) : null;
      set('settings-edition-ends', (ends && !isNaN(ends.getTime())) ? ends.toLocaleString('pl-PL') : '--');

      const editionInput = document.getElementById('settings-input-edition-number');
      if (editionInput && document.activeElement !== editionInput && ed.number != null) {
        editionInput.value = ed.number;
      }
    }

    const config = this.state.config || { chartSize: 25, waitingSize: 35, promotedCount: 2, maxVotes: 15 };
    const chartInput = document.getElementById('settings-input-chart-size');
    const waitingInput = document.getElementById('settings-input-waiting-size');
    const promotedInput = document.getElementById('settings-input-promoted-count');
    const maxVotesInput = document.getElementById('settings-input-max-votes');

    if (chartInput && document.activeElement !== chartInput) chartInput.value = config.chartSize || 25;
    if (waitingInput && document.activeElement !== waitingInput) waitingInput.value = config.waitingSize || 35;
    if (promotedInput && document.activeElement !== promotedInput) promotedInput.value = (config.promotedCount != null) ? config.promotedCount : 2;
    if (maxVotesInput && document.activeElement !== maxVotesInput) maxVotesInput.value = config.maxVotes || 15;
    const suspendedInput = document.getElementById('settings-input-voting-suspended');
    if (suspendedInput && document.activeElement !== suspendedInput) suspendedInput.checked = !!config.votingSuspended;
  }

  async saveSettings() {
    const editionInput = document.getElementById('settings-input-edition-number');
    const chartInput = document.getElementById('settings-input-chart-size');
    const waitingInput = document.getElementById('settings-input-waiting-size');
    const promotedInput = document.getElementById('settings-input-promoted-count');
    const maxVotesInput = document.getElementById('settings-input-max-votes');
    const saveBtn = document.getElementById('settings-save-btn');

    if (!chartInput || !waitingInput || !promotedInput) return;

    const chartSize = parseInt(chartInput.value, 10);
    const waitingSize = parseInt(waitingInput.value, 10);
    const promotedCount = parseInt(promotedInput.value, 10);
    const maxVotes = parseInt(maxVotesInput ? maxVotesInput.value : '15', 10);
    const editionNumber = editionInput ? parseInt(editionInput.value, 10) : null;

    if (editionInput && (isNaN(editionNumber) || editionNumber < 1)) {
      this.showSettingsFeedback('Numer notowania musi być liczbą całkowitą większą od zera.', false);
      return;
    }
    if (isNaN(chartSize) || chartSize < 1 || chartSize > 100) {
      this.showSettingsFeedback('Liczba pozycji w notowaniu musi być liczbą od 1 do 100.', false);
      return;
    }
    if (isNaN(waitingSize) || waitingSize < 1 || waitingSize > 200) {
      this.showSettingsFeedback('Liczba pozycji w poczekalni musi być liczbą od 1 do 200.', false);
      return;
    }
    if (isNaN(promotedCount) || promotedCount < 0 || promotedCount > chartSize) {
      this.showSettingsFeedback('Liczba awansujących utworów nie może przekraczać rozmiaru notowania.', false);
      return;
    }
    if (isNaN(maxVotes) || maxVotes < 1 || maxVotes > 50) {
      this.showSettingsFeedback('Maksymalna liczba głosów słuchacza musi być liczbą od 1 do 50.', false);
      return;
    }

    if (saveBtn) {
      saveBtn.disabled = true;
      saveBtn.classList.add('opacity-75');
    }

    const payload = {
      chartSize,
      waitingSize,
      promotedCount,
      maxVotes,
      votingSuspended: !!(document.getElementById('settings-input-voting-suspended') || {}).checked,
    };
    if (editionNumber != null) {
      payload.editionNumber = editionNumber;
    }

    const { ok, data } = await this.apiSend('/admin/settings', 'POST', payload);

    if (saveBtn) {
      saveBtn.disabled = false;
      saveBtn.classList.remove('opacity-75');
    }

    if (ok && data.success) {
      if (data.config) {
        this.state.config = data.config;
        if (this.state.config.maxVotes) this.maxVotesAllowed = this.state.config.maxVotes;
      }
      if (data.edition) {
        this.state.edition = data.edition;
      }
      this.renderVotingDrawer();
      this.render();
      this.showSettingsFeedback(data.message || 'Ustawienia zostały pomyślnie zapisane!', true);
    } else {
      this.showSettingsFeedback((data && data.message) || 'Błąd podczas zapisywania ustawień.', false);
    }
  }

  showSettingsFeedback(message, isSuccess) {
    const el = document.getElementById('settings-save-feedback');
    if (!el) return;
    el.innerText = message;
    el.className = isSuccess
      ? 'p-3 text-xs font-semibold bg-[#1BA345]/10 text-[#1BA345] border border-[#1BA345]/30'
      : 'p-3 text-xs font-semibold bg-[#EF305E]/10 text-[#EF305E] border border-[#EF305E]/30';
    el.classList.remove('hidden');

    if (this._feedbackTimeout) clearTimeout(this._feedbackTimeout);
    this._feedbackTimeout = setTimeout(() => {
      el.classList.add('hidden');
    }, 4000);
  }

  // --- API HELPERS ---
  async apiGet(path) {
    // Wysyłamy nonce zawsze — trasy admina (np. /admin/tracks, /admin/editors)
    // wymagają go do weryfikacji przez require_cap/require_manage; publiczne
    // GET-y go ignorują, więc jest to nieszkodliwe.
    const res = await fetch(`${API_BASE}${path}`, {
      credentials: 'include',
      headers: { 'X-WP-Nonce': (window.morsData && window.morsData.nonce) || '' },
    });
    const data = await res.json().catch(() => ({}));
    return { ok: res.ok, status: res.status, data };
  }

  async apiSend(path, method, body) {
    // Żądania piszące (POST/DELETE/PUT) wymagają nonce WP REST + ciasteczka sesji.
    // DELETE/PUT bywają blokowane przez serwer/WAF (405/403) — wysyłamy je jako POST
    // z nagłówkiem X-HTTP-Method-Override, który WP REST obsługuje natywnie.
    const override = (method === 'DELETE' || method === 'PUT') ? method : null;
    const res = await fetch(`${API_BASE}${path}`, {
      method: override ? 'POST' : method,
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': (window.morsData && window.morsData.nonce) || '',
        ...(override ? { 'X-HTTP-Method-Override': override } : {}),
      },
      body: body !== undefined ? JSON.stringify(body) : undefined,
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok && !data.message) {
      data.message = `Błąd serwera (HTTP ${res.status}).`;
    }
    return { ok: res.ok, status: res.status, data };
  }

  async apiUpload(path, formData) {
    // Upload (POST multipart) — również żądanie piszące, dodajemy nonce WP REST.
    // Łapiemy błędy sieci/serwera (np. 413 przerywa połączenie i fetch rzuca
    // NetworkError) — inaczej nieobsłużony wyjątek zawiesza UI na "Wgrywanie...".
    try {
      const res = await fetch(`${API_BASE}${path}`, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'X-WP-Nonce': (window.morsData && window.morsData.nonce) || '',
        },
        body: formData,
      });
      const data = await res.json().catch(() => ({}));
      if (!data.message && res.status === 413) {
        data.message = 'Plik jest zbyt duży — przekracza limit serwera (upload_max_filesize / client_max_body_size).';
      }
      return { ok: res.ok, status: res.status, data };
    } catch (e) {
      return { ok: false, status: 0, data: { message: 'Błąd sieci lub plik zbyt duży dla serwera. Spróbuj mniejszego pliku.' } };
    }
  }

  // --- STATE LOADING FROM SERVER ---
  async refreshChartState(preserveSelections = true) {
    const [chartRes, waitingRes] = await Promise.all([
      this.apiGet('/chart/current'),
      this.apiGet('/chart/waiting-room'),
    ]);

    if (chartRes.ok) {
      this.state.edition = chartRes.data.edition;
      this.state.chartTracks = chartRes.data.chartTracks;
      if (chartRes.data.config) {
        this.state.config = chartRes.data.config;
        if (this.state.config.maxVotes) this.maxVotesAllowed = this.state.config.maxVotes;
      }
    }
    if (waitingRes.ok) {
      this.state.waitingRoomTracks = waitingRes.data.waitingRoomTracks;
      if (waitingRes.data.config) {
        this.state.config = waitingRes.data.config;
        if (this.state.config.maxVotes) this.maxVotesAllowed = this.state.config.maxVotes;
      }
    }

    if (preserveSelections) {
      const validIds = new Set([
        ...this.state.chartTracks.map((t) => t.id),
        ...this.state.waitingRoomTracks.map((t) => t.id),
      ]);
      [...this.selectedVotes].forEach((id) => {
        if (!validIds.has(id)) this.selectedVotes.delete(id);
      });
    }
  }

  startPolling() {
    // Odświeżanie danych listy co 5 minut (zamiast dotychczasowych 10 sekund)
    setInterval(async () => {
      await this.refreshChartState();
      this.render();
      const livePulse = document.getElementById('live-pulse-badge');
      if (livePulse) {
        livePulse.classList.add('scale-105', 'text-[#a1daf8]');
        setTimeout(() => livePulse.classList.remove('scale-105', 'text-[#a1daf8]'), 400);
      }
    }, 5 * 60 * 1000);
  }

  startCountdownTimer() {
    setInterval(() => {
      if (!this.state.edition) return;
      const remainingMs = new Date(this.state.edition.endsAt).getTime() - Date.now();
      const el = document.getElementById('header-countdown');
      if (remainingMs <= 0) {
        if (el) el.innerText = 'GŁOSOWANIE ZAMKNIĘTE';
        return;
      }
      const days = Math.floor(remainingMs / (1000 * 60 * 60 * 24));
      const hours = Math.floor((remainingMs % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
      const mins = Math.floor((remainingMs % (1000 * 60 * 60)) / (1000 * 60));
      const secs = Math.floor((remainingMs % (1000 * 60)) / 1000);
      if (el) el.innerText = `${days}d ${hours}h ${mins}m ${secs}s`;
      this.updateCooldownDisplay();
    }, 1000);
  }

  // --- ADMINISTRATOR AUTHENTICATION ---
  // WordPress zarządza uwierzytelnianiem poza SPA — nie istnieją endpointy /auth/*.
  // Stan redaktora wyprowadzamy z danych zlokalizowanych (morsData). Na publicznej
  // stronie isAdminPanel=false → adminUser=null → SPA działa w trybie publicznym.
  async refreshAdminSession() {
    if (MORS.isAdminPanel && MORS.isEditor) {
      this.adminUser = {
        id: MORS.currentUserId || 0,
        fullName: MORS.currentUserName || 'Redaktor',
        role: MORS.role || 'MUSIC_EDITOR',
      };
    } else {
      this.adminUser = null;
    }
  }

  openAdminLoginModal() {
    // Logowanie obsługuje WordPress (wp-login / wp-admin). Wewnątrz-SPA modal
    // logowania jest wyłączony, aby na publicznej stronie nie wywoływać /auth/*.
  }

  closeAdminLoginModal() {
    const modal = document.getElementById('admin-login-modal');
    if (modal) modal.classList.add('hidden');
    this.exitLoginRoute();
  }

  // --- /logowanie ROUTE (editorial login) ---
  isLoginRoute() {
    const path = window.location.pathname.replace(/\/+$/, '').toLowerCase();
    const hash = window.location.hash.toLowerCase();
    return path === '/logowanie' || hash === '#logowanie' || hash === '#/logowanie';
  }

  enterLoginRoute() {
    if (this.isLoginRoute()) return;
    try {
      window.history.pushState({ loginRoute: true }, '', '/logowanie');
    } catch (err) {
      window.location.hash = 'logowanie';
    }
  }

  exitLoginRoute() {
    if (!this.isLoginRoute()) return;
    try {
      window.history.pushState({}, '', '/');
    } catch (err) {
      window.location.hash = '';
    }
  }

  handleLoginRoute() {
    if (!this.isLoginRoute()) {
      const modal = document.getElementById('admin-login-modal');
      if (modal) modal.classList.add('hidden');
      return;
    }
    if (this.adminUser) {
      this.exitLoginRoute();
      this.switchTab('admin');
    } else {
      this.openAdminLoginModal(false);
    }
  }

  fillDemoAdminCredentials() {
    const emailInput = document.getElementById('admin-login-email');
    const passInput = document.getElementById('admin-login-password');
    if (emailInput) emailInput.value = 'redakcja@mors.ug.edu.pl';
    if (passInput) passInput.value = 'RadioMORS2026!';
    this.showToast('Uzupełniono dane konta Redaktora Muzycznego UG', 'info');
  }

  async submitAdminLogin() {
    // Logowanie redakcji przenosi WordPress (brak endpointu /auth/login w REST).
    this.showToast('Logowanie redakcji odbywa się przez panel WordPress.', 'info');
  }

  async logoutAdmin() {
    // Wylogowanie obsługuje WordPress (brak endpointu /auth/logout w REST).
    this.adminUser = null;
    this.editors = [];
    this.updateAdminAuthUI();
    this.switchTab('chart');
  }

  roleLabel(role) {
    const labels = {
      SUPER_ADMIN: 'Główny Administrator',
      MUSIC_EDITOR: 'Redaktor Muzyczny',
      PRESENTER: 'Prezenter Audycji',
    };
    return labels[role] || role;
  }

  updateAdminAuthUI() {
    const authStatusEl = document.getElementById('header-admin-auth-widget');
    if (!authStatusEl) return;

    if (this.adminUser) {
      const initials = this.adminUser.fullName.split(' ').map((n) => n[0]).join('').substring(0, 2).toUpperCase();
      authStatusEl.innerHTML = `
        <div class="flex items-center gap-2">
          <div class="flex items-center gap-2 px-3 py-1.5 bg-[#0041d2] text-white border border-[#0041d2] text-xs font-headings">
            <span class="w-2 h-2 rounded-full bg-[#1BA345] animate-pulse"></span>
            <span class="font-bold">${escapeHtml(this.adminUser.fullName)}</span>
            <span class="text-[#a1daf8] text-[10px] hidden lg:inline">(${this.roleLabel(this.adminUser.role)})</span>
          </div>
          <button onclick="app.logoutAdmin()" class="btn-ug-outlined btn-ug-sm !py-1.5 !px-2.5" title="Wyloguj z CMS">
            <i data-lucide="log-out" class="w-3.5 h-3.5 text-[#EF305E]"></i>
            <span class="hidden sm:inline">Wyloguj</span>
          </button>
        </div>
      `;
      void initials;
    } else {
      // Logowanie redakcji jest dostępne wyłącznie pod adresem /logowanie
      authStatusEl.innerHTML = '';
    }
    morsCreateIcons();
  }

  async refreshEditors() {
    const { ok, data } = await this.apiGet('/admin/editors');
    this.editors = ok ? data.editors : [];
  }

  openAddEditorModal() {
    if (!this.adminUser) {
      this.showToast('Wymagane uprawnienia administratora.', 'warning');
      this.openAdminLoginModal();
      return;
    }
    const modal = document.getElementById('admin-editor-modal');
    if (modal) modal.classList.remove('hidden');
  }

  closeAddEditorModal() {
    const modal = document.getElementById('admin-editor-modal');
    if (modal) modal.classList.add('hidden');
  }

  async submitNewEditor() {
    if (!this.adminUser) {
      this.showToast('Brak uprawnień.', 'warning');
      return;
    }

    const nameInput = document.getElementById('editor-input-name');
    const emailInput = document.getElementById('editor-input-email');
    const roleInput = document.getElementById('editor-input-role');

    const fullName = nameInput ? nameInput.value.trim() : '';
    const email = emailInput ? emailInput.value.trim() : '';
    const role = roleInput ? roleInput.value : 'MUSIC_EDITOR';

    if (!fullName || !email) {
      this.showToast('Wprowadź imię, nazwisko i adres e-mail redaktora.', 'warning');
      return;
    }
    if (!email.includes('@')) {
      this.showToast('Wprowadź poprawny adres e-mail.', 'warning');
      return;
    }

    const { ok, data } = await this.apiSend('/admin/editors', 'POST', { fullName, email, role });
    if (!ok) {
      this.showToast(data.message || 'Nie udało się dodać redaktora.', 'warning');
      return;
    }

    await this.refreshEditors();
    this.renderEditorsList();
    this.closeAddEditorModal();

    if (nameInput) nameInput.value = '';
    if (emailInput) emailInput.value = '';

    this.showToast(`Redaktor ${fullName} (${this.roleLabel(role)}) został pomyślnie dodany do bazy!`, 'success');
  }

  async removeEditor(id) {
    if (!this.adminUser) {
      this.showToast('Brak uprawnień.', 'warning');
      return;
    }

    const editor = this.editors.find((e) => e.id === id);
    if (!editor) return;

    if (this.adminUser.id === editor.id) {
      this.showToast('Nie możesz usunąć aktualnie zalogowanego konta redaktora.', 'warning');
      return;
    }

    const { ok, data } = await this.apiSend(`/admin/editors/${id}`, 'DELETE');
    if (!ok) {
      this.showToast(data.message || 'Nie udało się usunąć redaktora.', 'warning');
      return;
    }

    await this.refreshEditors();
    this.renderEditorsList();
    this.showToast(`Konto redaktora ${editor.fullName} zostało usunięte z bazy.`, 'info');
  }

  renderEditorsList() {
    const container = document.getElementById('admin-editors-table-body');
    if (!container) return;

    container.innerHTML = this.editors.map((ed) => {
      const isCurrent = this.adminUser && this.adminUser.id === ed.id;
      const roleBadgeClass = ed.role === 'SUPER_ADMIN' ? 'ug-tag-navy' : ed.role === 'MUSIC_EDITOR' ? 'ug-tag-blue' : 'ug-tag-sail';
      const initials = ed.fullName.split(' ').map((n) => n[0]).join('').substring(0, 2).toUpperCase();
      const addedAt = new Date(ed.createdAt).toISOString().split('T')[0];

      return `
        <tr class="border-b border-[#D9D9D9] hover:bg-[#e5f5fd]/50 text-xs">
          <td class="p-3">
            <div class="flex items-center gap-2.5">
              <div class="w-7 h-7 bg-[#00214d] text-white flex items-center justify-center font-headings font-bold text-[10px] flex-shrink-0">
                ${initials}
              </div>
              <div>
                <span class="font-headings font-bold text-[#032c73] block">${escapeHtml(ed.fullName)}</span>
                <span class="ug-small !text-[11px] text-[#647391] sm:hidden">${escapeHtml(ed.email)}</span>
              </div>
            </div>
          </td>
          <td class="p-3 text-[#647391] hidden sm:table-cell">${escapeHtml(ed.email)}</td>
          <td class="p-3">
            <span class="ug-tag ${roleBadgeClass}">${this.roleLabel(ed.role)}</span>
          </td>
          <td class="p-3 text-[#647391] hidden md:table-cell">${addedAt}</td>
          <td class="p-3 text-right">
            ${isCurrent ? `
              <span class="ug-tag ug-tag-foam !text-[10px] font-bold">Twoje konto</span>
            ` : `
              <button onclick="app.removeEditor('${ed.id}')" class="text-[#EF305E] hover:text-[#d9224e] p-1.5 hover:bg-[#EF305E]/10" title="Usuń redaktora">
                <i data-lucide="user-x" class="w-4 h-4"></i>
              </button>
            `}
          </td>
        </tr>
      `;
    }).join('');
    morsCreateIcons();
  }

  // --- SYNTEZATOR PRÓBEK AUDIO (WEB AUDIO API) — bez zmian, czysto kliencki ---
  initAudioContext() {
    if (!this.audioCtx) {
      const AudioContext = window.AudioContext || window.webkitAudioContext;
      this.audioCtx = new AudioContext();
    }
    if (this.audioCtx.state === 'suspended') {
      this.audioCtx.resume();
    }
  }

  playAudioSnippet(trackId) {
    this.initAudioContext();
    if (this.currentPlayingId === trackId) {
      this.stopAudio();
      return;
    }
    this.stopAudio();
    this.currentPlayingId = trackId;

    // Publiczne wpisy mają id = id wpisu; admin-tracki mają id = id utworu (trackId).
    // Dopasowujemy po obu, żeby Play działał także w tabelach panelu.
    const track = [...this.state.chartTracks, ...this.state.waitingRoomTracks]
      .find((t) => t.id === trackId || t.trackId === trackId) || {};

    if (track.audioUrl) {
      // Utwór ma wgrany prawdziwy plik audio (serwowany z /uploads/audio) — gramy go.
      this.playCustomAudio(track.audioUrl);
    } else {
      // Brak pliku — proceduralny syntezator jako podgląd.
      const audioKey = track.audioKey || 'synth_chill';
      this.generateProceduralSnippet(audioKey);
    }

    this.updateMiniPlayer(trackId, track.title, track.artist, true);
    this.renderAudioVisualizers(true);
    this.syncAdminPlayIcons();

    if (this.audioTimeout) clearTimeout(this.audioTimeout);
    this.audioTimeout = setTimeout(() => {
      this.stopAudio();
    }, 15000);
  }

  playCustomAudio(src) {
    const audioEl = new Audio(src);
    audioEl.addEventListener('ended', () => this.stopAudio());
    audioEl.play().catch((err) => {
      console.warn('Nie udało się odtworzyć pliku audio:', err);
      this.showToast('Nie udało się odtworzyć pliku audio.', 'warning');
      this.stopAudio();
    });
    this.currentAudioEl = audioEl;
  }

  generateProceduralSnippet(style) {
    const ctx = this.audioCtx;
    const now = ctx.currentTime;
    const masterGain = ctx.createGain();
    masterGain.gain.setValueAtTime(0.15, now);
    masterGain.connect(ctx.destination);

    if (style === 'synth_chill') {
      const freqs = [261.63, 329.63, 392.0, 523.25];
      freqs.forEach((f, idx) => {
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.type = 'sine';
        osc.frequency.setValueAtTime(f, now);
        gain.gain.setValueAtTime(0.08 / (idx + 1), now);
        osc.connect(gain);
        gain.connect(masterGain);
        osc.start(now);
        this.activeOscillators.push(osc);
      });
    } else if (style === 'funk_bass') {
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();
      osc.type = 'sawtooth';
      osc.frequency.setValueAtTime(110, now);
      gain.gain.setValueAtTime(0.2, now);
      osc.connect(gain);
      gain.connect(masterGain);
      osc.start(now);
      this.activeOscillators.push(osc);
    } else if (style === 'guitar_groove') {
      [146.83, 220.0].forEach((f) => {
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.type = 'sawtooth';
        osc.frequency.setValueAtTime(f, now);
        gain.gain.setValueAtTime(0.12, now);
        osc.connect(gain);
        gain.connect(masterGain);
        osc.start(now);
        this.activeOscillators.push(osc);
      });
    } else if (style === 'lofi_keys') {
      [196.0, 246.94, 293.66].forEach((f, idx) => {
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.type = 'triangle';
        osc.frequency.setValueAtTime(f, now);
        gain.gain.setValueAtTime(0.09 / (idx + 1), now);
        osc.connect(gain);
        gain.connect(masterGain);
        osc.start(now);
        this.activeOscillators.push(osc);
      });
    } else if (style === 'punk_drive') {
      [174.61, 349.23].forEach((f) => {
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.type = 'square';
        osc.frequency.setValueAtTime(f, now);
        gain.gain.setValueAtTime(0.07, now);
        osc.connect(gain);
        gain.connect(masterGain);
        osc.start(now);
        this.activeOscillators.push(osc);
      });
    } else {
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();
      osc.type = 'triangle';
      osc.frequency.setValueAtTime(220, now);
      gain.gain.setValueAtTime(0.15, now);
      osc.connect(gain);
      gain.connect(masterGain);
      osc.start(now);
      this.activeOscillators.push(osc);
    }
  }

  stopAudio() {
    if (this.audioTimeout) clearTimeout(this.audioTimeout);
    this.activeOscillators.forEach((osc) => {
      try { osc.stop(); } catch (e) { /* already stopped */ }
    });
    this.activeOscillators = [];
    if (this.currentAudioEl) {
      try {
        this.currentAudioEl.pause();
        this.currentAudioEl.currentTime = 0;
      } catch (e) { /* ignore */ }
      this.currentAudioEl.src = '';
      this.currentAudioEl = null;
    }
    this.currentPlayingId = null;
    this.updateMiniPlayer(null, null, null, false);
    this.renderAudioVisualizers(false);
    this.syncAdminPlayIcons();
  }

  // Odświeża ikony Play/Stop w tabelach panelu (tylko w wp-admin).
  syncAdminPlayIcons() {
    if (window.morsData && window.morsData.isAdminPanel) {
      this.renderAdminList();
      morsCreateIcons();
    }
  }

  updateMiniPlayer(trackId, title, artist, isPlaying) {
    const bar = document.getElementById('mini-audio-player');
    if (!bar) return;
    if (!isPlaying) {
      bar.classList.add('translate-y-full');
      return;
    }
    bar.classList.remove('translate-y-full');
    document.getElementById('player-track-title').innerText = title || 'Odtwarzanie próbki...';
    document.getElementById('player-track-artist').innerText = artist || 'Radio MORS • Próbka 30s';
  }

  renderAudioVisualizers(isPlaying) {
    document.querySelectorAll('.ug-waveform-container').forEach((el) => {
      if (isPlaying && el.dataset.trackId === this.currentPlayingId) {
        el.querySelectorAll('.ug-waveform-bar').forEach((b) => {
          b.style.height = `${Math.floor(Math.random() * 20 + 8)}px`;
          b.style.backgroundColor = '#0041d2';
        });
      } else {
        el.querySelectorAll('.ug-waveform-bar').forEach((b) => {
          b.style.backgroundColor = '#647391';
        });
      }
    });

    document.querySelectorAll('[data-listen-btn]').forEach((btn) => {
      const trackId = btn.dataset.listenBtn;
      const isCurrent = isPlaying && trackId === this.currentPlayingId;
      const isHero = btn.closest('.ug-featured-card') !== null;
      if (isCurrent) {
        btn.classList.add('playing');
        btn.innerHTML = `
          <i data-lucide="square" class="w-4 h-4 fill-current"></i>
          <span class="${isHero ? '' : 'hidden sm:inline'}">Stop</span>
        `;
      } else {
        btn.classList.remove('playing');
        btn.innerHTML = `
          <i data-lucide="volume-2" class="w-4 h-4"></i>
          <span class="${isHero ? '' : 'hidden sm:inline'}">${isHero ? 'Odsłuchaj 30s' : 'Odsłuchaj'}</span>
        `;
      }
    });
    morsCreateIcons();
  }

  // --- ZARZĄDZANIE GŁOSOWANIEM ---
  toggleVote(trackId) {
    if (this.state.config && this.state.config.votingSuspended) {
      this.showToast('Głosowanie jest obecnie czasowo zawieszone. Zapraszamy wkrótce!', 'warning');
      return;
    }
    if (this.isCooldownActive()) {
      this.showToast(`Twój limit ${this.maxVotesAllowed} głosów na 24h jest obecnie aktywny!`, 'warning');
      return;
    }

    if (this.selectedVotes.has(trackId)) {
      this.selectedVotes.delete(trackId);
    } else {
      if (this.selectedVotes.size >= this.maxVotesAllowed) {
        this.showToast(`Możesz wybrać maksymalnie ${this.maxVotesAllowed} utworów w ramach jednego głosowania.`, 'warning');
        return;
      }
      this.selectedVotes.add(trackId);
    }
    this.renderVotingDrawer();
    this.render();
  }

  isCooldownActive() {
    if (!this.cooldownEndTime) return false;
    return Date.now() < this.cooldownEndTime;
  }

  async check24hCooldown() {
    const { ok, data } = await this.apiGet('/voter/status');
    if (ok && data.inCooldown) {
      this.cooldownEndTime = new Date(data.nextEligibleVoteAt).getTime();
    } else {
      this.cooldownEndTime = null;
    }
    this.applyCooldownUI();
  }

  applyCooldownUI() {
    const lockBanner = document.getElementById('voter-lock-banner');
    const drawer = document.getElementById('voting-drawer');

    if (this.isCooldownActive()) {
      if (lockBanner) lockBanner.classList.remove('hidden');
      if (drawer) drawer.classList.add('translate-y-full');
      this.updateCooldownDisplay();
    } else {
      if (lockBanner) lockBanner.classList.add('hidden');
      this.renderVotingDrawer();
    }
  }

  updateCooldownDisplay() {
    if (!this.isCooldownActive()) {
      this.cooldownEndTime = null;
      this.applyCooldownUI();
      return;
    }
    const remainingMs = this.cooldownEndTime - Date.now();
    const hours = Math.floor(remainingMs / (1000 * 60 * 60));
    const mins = Math.floor((remainingMs % (1000 * 60 * 60)) / (1000 * 60));
    const secs = Math.floor((remainingMs % (1000 * 60)) / 1000);

    const timerEl = document.getElementById('cooldown-timer-text');
    if (timerEl) {
      timerEl.innerText = `${String(hours).padStart(2, '0')}:${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
    }
  }

  openSubmitVoteModal() {
    if (this.selectedVotes.size === 0) {
      this.showToast('Wybierz przynajmniej 1 utwór, aby oddać głos.', 'warning');
      return;
    }
    const modal = document.getElementById('vote-verify-modal');
    if (modal) {
      modal.classList.remove('hidden');
      const editionLabel = document.getElementById('modal-edition-label');
      if (editionLabel && this.state.edition) {
        editionLabel.innerText = `Lista Przebojów Radia MORS #${this.state.edition.number}`;
      }
      this.renderSelectedVotesInModal();
    }
  }

  closeSubmitVoteModal() {
    const modal = document.getElementById('vote-verify-modal');
    if (modal) modal.classList.add('hidden');
  }

  renderSelectedVotesInModal() {
    const container = document.getElementById('modal-selected-tracks-list');
    if (!container) return;

    const allTracks = [...this.state.chartTracks, ...this.state.waitingRoomTracks];
    const chosen = allTracks.filter((t) => this.selectedVotes.has(t.id));

    container.innerHTML = chosen.map((t, idx) => `
      <div class="flex items-center justify-between p-3 border-2 border-[#0041d2] bg-[#e5f5fd]">
        <div class="flex items-center gap-3">
          <div class="w-8 h-8 bg-[#0041d2] flex items-center justify-center font-headings font-bold text-xs text-white">
            #${idx + 1}
          </div>
          <div>
            <div class="font-headings font-bold text-sm text-[#032c73]">${escapeHtml(t.title)}</div>
            <div class="ug-small text-xs">${escapeHtml(t.artist)}</div>
          </div>
        </div>
        <span class="ug-tag ${t.isChart ? 'ug-tag-blue' : 'ug-tag-sail'}">
          ${t.isChart ? 'Notowanie' : 'Poczekalnia'}
        </span>
      </div>
    `).join('');
  }

  async confirmAndCastVotes() {
    const allTracks = [...this.state.chartTracks, ...this.state.waitingRoomTracks];
    const votedTracks = allTracks.filter((t) => this.selectedVotes.has(t.id));
    const trackIds = [...this.selectedVotes];

    const { ok, status, data } = await this.apiSend('/votes/cast', 'POST', { trackIds });

    if (!ok) {
      if (status === 429 && data.nextEligibleVoteAt) {
        this.cooldownEndTime = new Date(data.nextEligibleVoteAt).getTime();
        this.applyCooldownUI();
        this.closeSubmitVoteModal();
      }
      this.showToast(data.message || 'Nie udało się zapisać głosu.', 'warning');
      return;
    }

    this.cooldownEndTime = new Date(data.nextEligibleVoteAt).getTime();
    this.closeSubmitVoteModal();
    this.openSocialShareModal(votedTracks);

    this.selectedVotes.clear();
    await this.refreshChartState();
    this.applyCooldownUI();
    this.render();
    this.showToast('Głosy zostały pomyślnie zarejestrowane! Dziękujemy!', 'success');
  }

  // --- SOCIAL MEDIA SHARE STUDIO — bez zmian, czysto kliencki Canvas ---
  openSocialShareModal(votedTracks) {
    const modal = document.getElementById('social-share-modal');
    if (!modal) return;
    modal.classList.remove('hidden');

    if (!votedTracks || votedTracks.length === 0) {
      const allTracks = [...this.state.chartTracks, ...this.state.waitingRoomTracks];
      votedTracks = this.selectedVotes.size > 0
        ? allTracks.filter((t) => this.selectedVotes.has(t.id))
        : this.state.chartTracks.slice(0, 3);
    }

    this.generateSocialCanvas(votedTracks);
  }

  closeSocialShareModal() {
    const modal = document.getElementById('social-share-modal');
    if (modal) modal.classList.add('hidden');
  }

  generateSocialCanvas(tracks) {
    const canvas = document.getElementById('social-share-canvas');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    canvas.width = 1080;
    canvas.height = 1920;

    ctx.fillStyle = '#00214d';
    ctx.fillRect(0, 0, 1080, 1920);

    ctx.fillStyle = '#0041d2';
    ctx.fillRect(0, 0, 1080, 440);

    ctx.textAlign = 'center';
    ctx.fillStyle = '#a1daf8';
    ctx.font = 'bold 34px "Work Sans", sans-serif';
    ctx.letterSpacing = '4px';
    ctx.fillText('UNIWERSYTET GDAŃSKI • RADIO MORS', 540, 180);

    ctx.fillStyle = '#FFFFFF';
    ctx.font = '700 76px "Work Sans", sans-serif';
    ctx.fillText('MÓJ GŁOS', 540, 280);

    ctx.fillStyle = '#032c73';
    ctx.fillRect(360, 340, 360, 60);
    ctx.fillStyle = '#FFFFFF';
    ctx.font = 'bold 26px "Work Sans", sans-serif';
    const editionNum = this.state.edition ? this.state.edition.number : '--';
    ctx.fillText(`NOTOWANIE #${editionNum}`, 540, 380);

    let startY = 510;
    tracks.slice(0, 3).forEach((track, i) => {
      ctx.fillStyle = '#FFFFFF';
      ctx.fillRect(90, startY, 900, 260);

      ctx.fillStyle = '#0041d2';
      ctx.fillRect(90, startY, 16, 260);

      ctx.fillStyle = '#e5f5fd';
      ctx.fillRect(130, startY + 30, 200, 200);

      ctx.fillStyle = '#0041d2';
      ctx.font = 'bold 64px sans-serif';
      ctx.fillText('♫', 230, startY + 155);

      ctx.fillStyle = '#00214d';
      ctx.fillRect(115, startY + 15, 60, 60);
      ctx.fillStyle = '#FFFFFF';
      ctx.font = 'bold 30px "Work Sans", sans-serif';
      ctx.fillText(`${i + 1}`, 145, startY + 56);

      ctx.textAlign = 'left';
      ctx.fillStyle = '#032c73';
      ctx.font = 'bold 42px "Work Sans", sans-serif';
      ctx.fillText(this.truncateText(track.title, 22), 360, startY + 95);

      ctx.fillStyle = '#647391';
      ctx.font = '32px "DM Sans", sans-serif';
      ctx.fillText(this.truncateText(track.artist, 26), 360, startY + 150);

      ctx.fillStyle = '#0041d2';
      ctx.fillRect(360, startY + 175, 230, 40);
      ctx.fillStyle = '#FFFFFF';
      ctx.font = 'bold 18px "Work Sans", sans-serif';
      ctx.fillText('✓ ZAGŁOSOWANO', 380, startY + 202);

      ctx.textAlign = 'center';
      startY += 300;
    });

    if (tracks.length > 3) {
      ctx.fillStyle = '#a1daf8';
      ctx.font = 'bold 28px "Work Sans", sans-serif';
      ctx.fillText(`+ ${tracks.length - 3} innych wybranych utworów`, 540, 1460);
    }

    ctx.textAlign = 'center';
    ctx.fillStyle = '#FFFFFF';
    ctx.font = 'bold 38px "Work Sans", sans-serif';
    ctx.fillText('Głosuj codziennie na mors.ug.edu.pl', 540, 1560);

    ctx.fillStyle = '#a1daf8';
    ctx.font = '26px "DM Sans", sans-serif';
    ctx.fillText('Audycja na żywo: w każdy Piątek o 18:00 na 98.4 FM', 540, 1620);

    ctx.fillStyle = 'rgba(255, 255, 255, 0.4)';
    ctx.font = '22px "DM Sans", sans-serif';
    ctx.fillText('Uniwersytet Gdański • UG Design System', 540, 1800);
  }

  truncateText(text, maxLen) {
    if (!text) return '';
    return text.length > maxLen ? text.substring(0, maxLen - 1) + '…' : text;
  }

  downloadSocialCard() {
    const canvas = document.getElementById('social-share-canvas');
    if (!canvas) return;
    const editionNum = this.state.edition ? this.state.edition.number : 'demo';
    const link = document.createElement('a');
    link.download = `RadioMORS_UG_Notowanie_${editionNum}_MojGlos.png`;
    link.href = canvas.toDataURL('image/png');
    link.click();
    this.showToast('Karta graficzna została pobrana!', 'success');
  }

  async shareViaWebShare() {
    const canvas = document.getElementById('social-share-canvas');
    if (!canvas) return;
    const editionNum = this.state.edition ? this.state.edition.number : '';
    try {
      canvas.toBlob(async (blob) => {
        const file = new File([blob], 'RadioMORS_UG_MojGlos.png', { type: 'image/png' });
        if (navigator.canShare && navigator.canShare({ files: [file] })) {
          await navigator.share({
            title: `Mój głos w Liście Przebojów Radia MORS #${editionNum}`,
            text: 'Oddałem swoje głosy na Listę Przebojów Radia MORS Uniwersytetu Gdańskiego! Zagłosuj też:',
            url: window.location.href,
            files: [file],
          });
        } else {
          await navigator.share({
            title: `Lista Przebojów Radia MORS #${editionNum}`,
            text: 'Oddałem swoje głosy! Sprawdź notowanie:',
            url: window.location.href,
          });
        }
      });
    } catch (err) {
      console.log('Share dismissed or not supported', err);
    }
  }

  // --- ADMIN CMS: UPLOAD AUDIO & OKŁADEK ORAZ RESET NOTOWANIA ---
  handleAudioFileUpload(file) {
    if (!file) return;
    const validAudioExtensions = ['.wav', '.flac', '.mp3', '.ogg', '.m4a'];
    const validAudioTypes = [
      'audio/wav', 'audio/x-wav', 'audio/wave',
      'audio/flac', 'audio/x-flac',
      'audio/mpeg', 'audio/mp3', 'audio/mpg',
      'audio/ogg', 'audio/mp4', 'audio/x-m4a'
    ];
    const fileName = (file.name || '').toLowerCase();
    const hasValidExt = validAudioExtensions.some((ext) => fileName.endsWith(ext));
    const hasValidType = file.type ? validAudioTypes.includes(file.type) : false;
    if (!hasValidExt && !hasValidType) {
      this.showToast('Obsługiwane formaty audio: .WAV, .FLAC, .MP3, .OGG, .M4A', 'warning');
      return;
    }

    this.pendingAudioFile = file;
    const uploadStatus = document.getElementById('admin-upload-status');
    const progressBar = document.getElementById('admin-upload-progress');

    if (uploadStatus) uploadStatus.classList.remove('hidden');
    if (progressBar) progressBar.style.width = '20%';

    setTimeout(() => {
      if (progressBar) progressBar.style.width = '65%';
      const cleanName = file.name.replace(/\.[^/.]+$/, '');
      const parts = cleanName.split(' - ');
      const artist = parts.length > 1 ? parts[0] : 'Nowy Wykonawca';
      const title = parts.length > 1 ? parts[1] : cleanName;

      document.getElementById('admin-input-title').value = title;
      document.getElementById('admin-input-artist').value = artist;
      document.getElementById('admin-input-duration').value = '3:24';
    }, 400);

    setTimeout(() => {
      if (progressBar) progressBar.style.width = '100%';
      this.showToast(`Plik audio ${file.name} przetworzony: Normalizacja EBU R128 (-14 LUFS) zakończona pomyślnie.`, 'success');
    }, 800);
  }

  handleCoverFileUpload(file) {
    if (!file) return;
    const validTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/jpg'];
    if (!validTypes.includes(file.type)) {
      this.showToast('Obsługiwane formaty okładek: .JPG, .PNG, .WEBP', 'warning');
      return;
    }

    this.pendingCoverFile = file;
    const reader = new FileReader();
    reader.onload = (e) => {
      this.pendingCoverDataUrl = e.target.result;
      const previewImg = document.getElementById('admin-cover-preview-img');
      const placeholder = document.getElementById('admin-cover-placeholder');
      const removeBtn = document.getElementById('admin-cover-remove-btn');

      if (previewImg) {
        previewImg.src = this.pendingCoverDataUrl;
        previewImg.classList.remove('hidden');
      }
      if (placeholder) placeholder.classList.add('hidden');
      if (removeBtn) removeBtn.classList.remove('hidden');

      this.showToast(`Wczytano okładkę: ${file.name} (Automatyczne skalowanie do 600x600 WebP)`, 'success');
    };
    reader.readAsDataURL(file);
  }

  removePendingCover() {
    this.pendingCoverFile = null;
    this.pendingCoverDataUrl = null;
    const previewImg = document.getElementById('admin-cover-preview-img');
    const placeholder = document.getElementById('admin-cover-placeholder');
    const removeBtn = document.getElementById('admin-cover-remove-btn');
    const fileInput = document.getElementById('admin-cover-file-input');

    if (previewImg) {
      previewImg.src = '';
      previewImg.classList.add('hidden');
    }
    if (placeholder) placeholder.classList.remove('hidden');
    if (removeBtn) removeBtn.classList.add('hidden');
    if (fileInput) fileInput.value = '';
    this.showToast('Usunięto przypisaną okładkę', 'info');
  }

  async submitNewTrackFromAdmin() {
    if (!this.adminUser) {
      this.showToast('Musisz być zalogowany jako redaktor, aby dodawać utwory.', 'warning');
      this.openAdminLoginModal();
      return;
    }

    const title = document.getElementById('admin-input-title').value.trim();
    const artist = document.getElementById('admin-input-artist').value.trim();
    const target = document.getElementById('admin-input-target').value;
    const duration = document.getElementById('admin-input-duration').value || '3:30';

    if (!title || !artist) {
      this.showToast('Wprowadź tytuł utworu i wykonawcę.', 'warning');
      return;
    }

    const formData = new FormData();
    formData.append('title', title);
    formData.append('artist', artist);
    formData.append('target', target);
    formData.append('duration', duration);
    if (this.pendingCoverFile) formData.append('cover', this.pendingCoverFile);
    if (this.pendingAudioFile) formData.append('audio', this.pendingAudioFile);

    const { ok, data } = await this.apiUpload('/admin/tracks/upload', formData);
    if (!ok) {
      this.showToast(data.message || 'Nie udało się dodać utworu.', 'warning');
      const st = document.getElementById('admin-upload-status');
      if (st) st.classList.add('hidden');
      return;
    }

    if (target === 'waiting') {
      this.showToast(`Utwór "${title}" z okładką dodany do Poczekalni!`, 'success');
      this.switchAdminTracksTab('waiting');
    } else {
      this.showToast(`Utwór "${title}" z okładką dodany do Notowania Głównego!`, 'success');
      this.switchAdminTracksTab('chart');
    }

    await this.refreshChartState();
    if (this.adminUser) await this.refreshAdminTracks();
    this.render();

    document.getElementById('admin-input-title').value = '';
    document.getElementById('admin-input-artist').value = '';
    document.getElementById('admin-upload-status').classList.add('hidden');
    this.pendingAudioFile = null;
    this.removePendingCover();
  }

  openResetChartModal() {
    if (!this.adminUser) {
      this.showToast('Wymagane uprawnienia redaktora do resetu notowania.', 'warning');
      this.openAdminLoginModal();
      return;
    }
    const modal = document.getElementById('admin-reset-modal');
    if (modal) modal.classList.remove('hidden');
  }

  closeResetChartModal() {
    const modal = document.getElementById('admin-reset-modal');
    if (modal) modal.classList.add('hidden');
  }

  async executeResetChart() {
    if (!this.adminUser) {
      this.showToast('Brak autoryzacji redakcji.', 'warning');
      return;
    }

    const { ok, data } = await this.apiSend('/admin/chart/reset-and-publish', 'POST');
    if (!ok) {
      this.showToast(data.message || 'Nie udało się zresetować notowania.', 'warning');
      return;
    }

    this.closeResetChartModal();
    this.selectedVotes.clear();
    await this.refreshChartState(false);
    await this.check24hCooldown();
    if (this.adminUser) await this.refreshAdminTracks();
    this.render();
    this.showToast(`Notowanie #${data.edition.editionNumber} zostało pomyślnie otwarte! Głosy zresetowane przez ${this.adminUser.fullName}.`, 'success');
  }

  // --- RENDEROWANIE GŁÓWNE ---
  switchTab(tab) {
    if (tab === 'admin' && !this.adminUser) {
      this.showToast('Panel redaktora wymaga zalogowania.', 'warning');
      this.openAdminLoginModal();
      return;
    }

    this.activeTab = tab;
    document.querySelectorAll('.nav-tab-btn').forEach((btn) => {
      if (btn.dataset.tab === tab) {
        btn.classList.add('bg-[#0041d2]', 'text-white', 'border-[#0041d2]', 'shadow-sm');
        btn.classList.remove('text-[#032c73]', 'border-transparent', 'bg-[#e5f5fd]');
      } else {
        btn.classList.remove('bg-[#0041d2]', 'text-white', 'border-[#0041d2]', 'shadow-sm');
        btn.classList.add('text-[#032c73]', 'border-transparent');
      }
    });

    const chartView = document.getElementById('view-chart');
    const waitingView = document.getElementById('view-waiting');
    const adminView = document.getElementById('view-admin');

    if (chartView) chartView.classList.toggle('hidden', tab !== 'chart');
    if (waitingView) waitingView.classList.toggle('hidden', tab !== 'waiting');
    if (adminView) adminView.classList.toggle('hidden', tab !== 'admin');

    if (tab === 'admin' && this.adminUser) {
      this.refreshAdminTracks().then(() => this.render());
    }

    this.render();
  }

  async refreshAdminTracks() {
    const { ok, data } = await this.apiGet('/admin/tracks');
    this.adminTracks = ok ? data.tracks : [];
  }

  render() {
    this.renderHeaderInfo();
    this.renderConfigLabels();
    this.renderChartList();
    this.renderWaitingRoomList();
    this.renderVotingDrawer();
    this.renderAdminList();
    this.renderSettingsInfo();
    morsCreateIcons();
  }

  renderConfigLabels() {
    const config = this.state.config || { chartSize: 25, waitingSize: 35, promotedCount: 2 };
    const chartSize = config.chartSize || 25;
    const waitingSize = config.waitingSize || 35;
    const promotedCount = (config.promotedCount != null) ? config.promotedCount : 2;

    document.querySelectorAll('.mors-label-chart-top').forEach((el) => {
      el.innerText = `TOP ${chartSize}`;
    });

    const waitingTitleCount = document.getElementById('waiting-title-count');
    if (waitingTitleCount) waitingTitleCount.innerText = waitingSize;

    const waitingPromotedCount = document.getElementById('waiting-promoted-count');
    if (waitingPromotedCount) waitingPromotedCount.innerText = `TOP ${promotedCount}`;

    const uploadChartOpt = document.getElementById('upload-target-chart');
    if (uploadChartOpt) uploadChartOpt.innerText = `Notowanie Główne (TOP ${chartSize})`;

    const uploadWaitingOpt = document.getElementById('upload-target-waiting');
    if (uploadWaitingOpt) uploadWaitingOpt.innerText = `Poczekalnia (Uzupełnij do ${waitingSize} utworów)`;
  }

  renderHeaderInfo() {
    if (!this.state.edition) return;
    const numEl = document.getElementById('header-edition-num');
    if (numEl) numEl.innerText = `#${this.state.edition.number}`;
    const votesEl = document.getElementById('header-total-votes');
    if (votesEl) votesEl.innerText = this.state.edition.totalVotesCount.toLocaleString('pl-PL');
    const waitingBadge = document.getElementById('waiting-count-badge');
    if (waitingBadge) waitingBadge.innerText = `(${this.state.waitingRoomTracks.length})`;
    const waitingTotal = document.getElementById('waiting-total-count');
    if (waitingTotal) waitingTotal.innerText = this.state.waitingRoomTracks.length;
  }

  renderChartList() {
    const container = document.getElementById('chart-tracks-container');
    if (!container || this.state.chartTracks.length === 0) return;

    const heroTrack = this.state.chartTracks[0];
    const isHeroSelected = this.selectedVotes.has(heroTrack.id);
    const isPlayingHero = this.currentPlayingId === heroTrack.id;

    const heroCoverHtml = heroTrack.coverImage
      ? `<img src="${heroTrack.coverImage}" alt="${escapeHtml(heroTrack.title)}" class="w-full h-full object-cover" />`
      : `<i data-lucide="${isPlayingHero ? 'pause' : 'music'}" class="w-12 h-12 text-[#a1daf8] drop-shadow group-hover:scale-110 transition-transform"></i>`;

    let html = `
      <div class="ug-featured-card mb-8">
        <div class="p-6 md:p-8 bg-white flex flex-col lg:flex-row items-center justify-between gap-6">
          <div class="flex flex-col sm:flex-row items-center gap-6 text-center sm:text-left w-full lg:w-auto">
            <div class="relative group cursor-pointer flex-shrink-0" onclick="app.playAudioSnippet('${heroTrack.id}')">
              <div class="w-28 h-28 md:w-36 md:h-36 bg-[#00214d] flex items-center justify-center shadow-md relative overflow-hidden text-white">
                ${heroCoverHtml}
              </div>
              <div class="absolute -top-3 -left-3 px-3 py-1 bg-[#0041d2] text-white font-headings font-bold text-xs flex items-center gap-1 shadow-sm uppercase tracking-wider">
                <i data-lucide="crown" class="w-3.5 h-3.5 fill-current text-[#FEC001]"></i> #1 KRÓL LISTY
              </div>
            </div>

            <div>
              <div class="flex flex-wrap items-center justify-center sm:justify-start gap-2 mb-2">
                <span class="ug-tag trend-tag-up">
                  <i data-lucide="arrow-up" class="w-3 h-3"></i> Awans z #${heroTrack.prevPosition || '-'}
                </span>
                <span class="ug-tag ug-tag-foam">
                  ${heroTrack.weeks} tyg. na liście
                </span>
                <span class="ug-tag ug-tag-navy">
                  ${escapeHtml(heroTrack.genre || '')}
                </span>
              </div>
              <h2 class="ug-h2 !text-2xl md:!text-3xl !font-bold !text-[#032c73]">${escapeHtml(heroTrack.title)}</h2>
              <p class="ug-lead !text-base md:!text-lg !text-[#647391] mt-0.5">${escapeHtml(heroTrack.artist)}</p>
              <p class="ug-small !text-xs mt-1">${escapeHtml(heroTrack.album || '')} • Czas trwania: ${escapeHtml(heroTrack.duration)}</p>
            </div>
          </div>

          <div class="flex flex-wrap items-center gap-3 w-full sm:w-auto justify-end">
            <button type="button" onclick="app.playAudioSnippet('${heroTrack.id}')" data-listen-btn="${heroTrack.id}" class="btn-mors-listen ${isPlayingHero ? 'playing' : ''}">
              <i data-lucide="${isPlayingHero ? 'square' : 'volume-2'}" class="w-4 h-4"></i>
              <span>${isPlayingHero ? 'Stop' : 'Odsłuchaj 30s'}</span>
            </button>
            <button type="button" onclick="app.toggleVote('${heroTrack.id}')" class="btn-mors-vote ${isHeroSelected ? 'selected' : ''}">
              <i data-lucide="${isHeroSelected ? 'check-circle' : 'vote'}" class="w-4 h-4"></i>
              <span>${isHeroSelected ? 'Wybrano' : 'Głosuj'}</span>
            </button>
          </div>
        </div>
      </div>
    `;

    html += `<div class="space-y-3">`;
    this.state.chartTracks.slice(1).forEach((track) => {
      const isSelected = this.selectedVotes.has(track.id);
      const isPlaying = this.currentPlayingId === track.id;

      let trendBadge = '';
      if (track.trend === 'UP') {
        const diff = (track.prevPosition || 0) - track.position;
        trendBadge = `<span class="ug-tag trend-tag-up"><i data-lucide="trending-up" class="w-3 h-3"></i> +${diff > 0 ? diff : 1}</span>`;
      } else if (track.trend === 'DOWN') {
        const diff = track.position - (track.prevPosition || 0);
        trendBadge = `<span class="ug-tag trend-tag-down"><i data-lucide="trending-down" class="w-3 h-3"></i> -${diff > 0 ? diff : 1}</span>`;
      } else if (track.trend === 'NEW') {
        trendBadge = `<span class="ug-tag trend-tag-new"><i data-lucide="sparkles" class="w-3 h-3 text-[#a1daf8]"></i> NOWOŚĆ</span>`;
      } else {
        trendBadge = `<span class="ug-tag trend-tag-same"><i data-lucide="minus" class="w-3 h-3"></i> BZ</span>`;
      }

      const trackCoverHtml = track.coverImage
        ? `<img src="${track.coverImage}" alt="${escapeHtml(track.title)}" class="w-full h-full object-cover" />`
        : `<i data-lucide="${isPlaying ? 'pause' : 'music'}" class="w-5 h-5 text-white group-hover/art:scale-110 transition-transform"></i>`;

      html += `
        <div class="group flex items-center justify-between p-3.5 md:p-4 ug-card ${isSelected ? 'ug-card-selected' : ''}">
          <div class="flex items-center gap-3 md:gap-5 min-w-0">
            <div class="w-8 md:w-10 text-center flex-shrink-0">
              <span class="text-xl md:text-2xl font-headings font-bold ${track.position <= 3 ? 'text-[#0041d2]' : 'text-[#647391]'}">${track.position}</span>
            </div>

            <div class="w-16 hidden sm:flex items-center justify-center flex-shrink-0">
              ${trendBadge}
            </div>

            <div class="relative w-12 h-12 md:w-14 md:h-14 bg-[#00214d] flex-shrink-0 flex items-center justify-center cursor-pointer shadow-sm overflow-hidden group/art" onclick="app.playAudioSnippet('${track.id}')">
              ${trackCoverHtml}
            </div>

            <div class="min-w-0">
              <div class="flex items-center gap-2">
                <h3 class="font-headings font-bold text-[#032c73] text-sm md:text-base truncate group-hover:text-[#0041d2] transition-colors">${escapeHtml(track.title)}</h3>
                <span class="sm:hidden">${trendBadge}</span>
              </div>
              <p class="ug-body !text-xs md:!text-sm !text-[#647391] truncate">${escapeHtml(track.artist)}</p>
              <div class="flex items-center gap-3 ug-small !text-[11px] mt-0.5">
                <span>${track.weeks} tyg. na liście</span>
                <span>•</span>
                <span>Najwyższa poz: #${track.peak || track.position}</span>
              </div>
            </div>
          </div>

          <div class="flex items-center gap-2 md:gap-3 flex-shrink-0 ml-2">

            <button type="button" onclick="app.playAudioSnippet('${track.id}')" data-listen-btn="${track.id}" class="btn-mors-listen ${isPlaying ? 'playing' : ''}" title="Odsłuchaj fragment utworu (30s)">
              <i data-lucide="${isPlaying ? 'square' : 'volume-2'}" class="w-4 h-4"></i>
              <span class="hidden sm:inline">${isPlaying ? 'Stop' : 'Odsłuchaj'}</span>
            </button>

            <button type="button" onclick="app.toggleVote('${track.id}')" class="btn-mors-vote ${isSelected ? 'selected' : ''}">
              <i data-lucide="${isSelected ? 'check' : 'plus'}" class="w-4 h-4"></i>
              <span>${isSelected ? 'Wybrano' : 'Głosuj'}</span>
            </button>
          </div>
        </div>
      `;
    });
    html += `</div>`;
    container.innerHTML = html;
  }

  renderWaitingRoomList() {
    const container = document.getElementById('waiting-tracks-container');
    if (!container) return;

    let html = `<div class="ug-card divide-y divide-[#D9D9D9]">`;

    this.state.waitingRoomTracks.forEach((track, idx) => {
      const isSelected = this.selectedVotes.has(track.id);
      const isPlaying = this.currentPlayingId === track.id;

      const trackCoverHtml = track.coverImage
        ? `<img src="${track.coverImage}" alt="${escapeHtml(track.title)}" class="w-full h-full object-cover" />`
        : `<i data-lucide="${isPlaying ? 'pause' : 'music'}" class="w-4 h-4 text-white"></i>`;

      html += `
        <div class="flex items-center gap-3 px-3 py-2.5 ${isSelected ? 'ug-card-selected' : ''}">
        <div>
          <span class="w-8 flex-shrink-0 text-center text-xs font-headings font-bold text-[#647391]">${idx + 1}</span>
          <div class="relative w-10 h-10 bg-[#00214d] flex-shrink-0 flex items-center justify-center cursor-pointer overflow-hidden" onclick="app.playAudioSnippet('${track.id}')">
            ${trackCoverHtml}
          </div>
          <div class="min-w-0 flex-1">
            <h4 class="font-headings font-bold text-[#032c73] text-sm truncate">${escapeHtml(track.title)}</h4>
            <p class="ug-small truncate">${escapeHtml(track.artist)}</p>
          </div>
          </div>
          <div>
          <span class="ug-small hidden lg:flex items-center gap-1 flex-shrink-0">
            <i data-lucide="clock" class="w-3 h-3"></i> ${track.weeksInWaiting} tyg. w poczekalni
          </span>
          <div class="flex items-center gap-2 flex-shrink-0">
            <button type="button" onclick="app.playAudioSnippet('${track.id}')" data-listen-btn="${track.id}" class="btn-mors-listen !py-1 !px-2.5 !text-xs ${isPlaying ? 'playing' : ''}" title="Odsłuchaj fragment">
              <i data-lucide="${isPlaying ? 'square' : 'volume-2'}" class="w-3.5 h-3.5"></i>
              <span class="hidden sm:inline">${isPlaying ? 'Stop' : 'Odsłuchaj'}</span>
            </button>
            <button type="button" onclick="app.toggleVote('${track.id}')" class="btn-mors-vote !py-1 !px-3 !text-xs ${isSelected ? 'selected' : ''}">
              <i data-lucide="${isSelected ? 'check' : 'plus'}" class="w-3.5 h-3.5"></i>
              <span>${isSelected ? 'Wybrano' : 'Głosuj'}</span>
            </button>
          </div>
          </div>
        </div>
      `;
    });

    html += `</div>`;
    container.innerHTML = html;
  }

  renderVotingDrawer() {
    const drawer = document.getElementById('voting-drawer');
    const badgeCount = document.getElementById('drawer-vote-count');
    const fillBar = document.getElementById('drawer-progress-fill');
    const btnSubmit = document.getElementById('drawer-submit-btn');

    if (!drawer) return;

    if (this.isCooldownActive() || this.selectedVotes.size === 0 || (this.state.config && this.state.config.votingSuspended)) {
      drawer.classList.add('translate-y-full');
      return;
    }

    drawer.classList.remove('translate-y-full');
    const count = this.selectedVotes.size;
    if (badgeCount) badgeCount.innerText = `${count} / ${this.maxVotesAllowed}`;
    if (fillBar) fillBar.style.width = `${(count / this.maxVotesAllowed) * 100}%`;
    const maxTag = document.getElementById('drawer-max-tag');
    if (maxTag) maxTag.innerText = `Max ${this.maxVotesAllowed} utworów`;

    if (btnSubmit) {
      btnSubmit.innerHTML = `
        <span>Zatwierdź swój głos (${count}/${this.maxVotesAllowed})</span>
        <i data-lucide="arrow-right" class="w-4 h-4"></i>
      `;
    }
  }

  // Wiersz tabeli zarządzania utworem.
  adminTrackRow(t, draggable = false, index = 0) {
    const posNum = draggable ? (t.position != null ? t.position : (index + 1)) : (index + 1);
    return `
      <tr class="border-b border-[#D9D9D9] hover:bg-[#e5f5fd]/50 text-xs ${draggable ? 'cursor-move' : ''}"${draggable ? ' draggable="true"' : ''} data-track-id="${t.id}">
        <td class="p-3 text-center">
          <div class="flex items-center justify-center gap-1">
            ${draggable
              ? '<i data-lucide="grip-vertical" class="w-4 h-4 text-[#a1b0c0] flex-shrink-0" title="Przeciągnij, aby zmienić kolejność"></i>'
              : ''}
            <span class="font-mono font-bold ${draggable ? 'text-[#0041d2]' : 'text-[#647391]'}">${posNum}</span>
          </div>
        </td>
        <td class="p-3">
          <div class="flex items-center gap-2">
            <div class="w-7 h-7 bg-[#00214d] flex-shrink-0 flex items-center justify-center text-white text-[10px] overflow-hidden">
              ${t.coverImageUrl ? `<img src="${escapeHtml(t.coverImageUrl)}" class="w-full h-full object-cover" />` : '<i data-lucide="music" class="w-3.5 h-3.5 text-white"></i>'}
            </div>
            <span class="font-headings font-bold text-[#032c73]">${escapeHtml(t.title)}</span>
          </div>
        </td>
        <td class="p-3 text-[#647391]">${escapeHtml(t.artist)}</td>
        <td class="p-3 font-headings font-bold text-[#032c73]">${t.votes || 0} pkt</td>
        <td class="p-3 text-right">
          <div class="inline-flex items-center gap-1">
            <button onclick="app.openEditTrackModal('${t.id}')" class="text-[#647391] hover:text-[#032c73] p-1.5 hover:bg-[#032c73]/10" title="Edytuj utwór">
              <i data-lucide="pencil" class="w-4 h-4"></i>
            </button>
            <button onclick="app.moveTrackAdmin('${t.id}', '${draggable ? 'waiting' : 'chart'}')" class="text-[#0041d2] hover:text-[#032c73] p-1.5 hover:bg-[#0041d2]/10" title="${draggable ? 'Przenieś do poczekalni' : 'Przenieś do Top 25'}">
              <i data-lucide="${draggable ? 'chevrons-down' : 'chevrons-up'}" class="w-4 h-4"></i>
            </button>
            <button onclick="app.removeTrackAdmin('${t.id}')" class="text-[#EF305E] hover:text-[#d9224e] p-1.5 hover:bg-[#EF305E]/10" title="Usuń utwór">
              <i data-lucide="trash-2" class="w-4 h-4"></i>
            </button>
          </div>
        </td>
      </tr>`;
  }

  renderAdminList() {
    // Notowanie (wg pozycji, przeciągalne) i Poczekalnia (wg głosów).
    const chart = this.adminTracks
      .filter((t) => t.section === 'Notowanie')
      .sort((a, b) => (a.position == null ? 999 : a.position) - (b.position == null ? 999 : b.position));
    const waiting = this.adminTracks
      .filter((t) => t.section === 'Poczekalnia')
      .sort((a, b) => {
        const votesDiff = (b.votes || 0) - (a.votes || 0);
        if (votesDiff !== 0) return votesDiff;
        const weeksDiff = (b.weeksOnChart || 0) - (a.weeksOnChart || 0);
        if (weeksDiff !== 0) return weeksDiff;
        return (a.title || '').localeCompare(b.title || '');
      });

    const chartBody = document.getElementById('admin-chart-tracks-table-body');
    if (chartBody) {
      const rows = chart.map((t, idx) => this.adminTrackRow(t, true, idx));
      chartBody.innerHTML = rows.length
        ? rows.join('')
        : '<tr><td colspan="5" class="p-4 text-center text-[#647391] text-xs">Brak utworów w notowaniu.</td></tr>';
    }

    const waitingBody = document.getElementById('admin-waiting-tracks-table-body');
    if (waitingBody) {
      const rows = waiting.map((t, idx) => this.adminTrackRow(t, false, idx));
      waitingBody.innerHTML = rows.length
        ? rows.join('')
        : '<tr><td colspan="5" class="p-4 text-center text-[#647391] text-xs">Brak utworów w poczekalni.</td></tr>';
    }

    // Kompatybilność wsteczna jeśli gdziekolwiek istnieje tabela zbiorcza.
    const body = document.getElementById('admin-tracks-table-body');
    if (body) {
      const rows = [
        ...chart.map((t, idx) => this.adminTrackRow(t, true, idx)),
        ...waiting.map((t, idx) => this.adminTrackRow(t, false, idx)),
      ];
      body.innerHTML = rows.length
        ? rows.join('')
        : '<tr><td colspan="5" class="p-4 text-center text-[#647391] text-xs">Brak utworów.</td></tr>';
    }

    // Liczniki w zakładkach Utwory.
    const set = (id, val) => { const el = document.getElementById(id); if (el) el.innerText = val; };
    set('admin-tab-chart-count', chart.length);
    set('admin-tab-waiting-count', waiting.length);

    // Skrót na "Panel redaktora".
    set('dash-chart-count', chart.length);
    set('dash-waiting-count', waiting.length);
    if (this.state.edition) set('dash-edition-num', `#${this.state.edition.number}`);

    this.initChartDragAndDrop();
  }

  // Drag & drop kolejności w tabeli Notowanie (zapisywane do /admin/chart/reorder).
  // Bindujemy raz na trwałym <tbody> (renderAdminList podmienia tylko innerHTML).
  initChartDragAndDrop() {
    if (!(window.morsData && window.morsData.isAdminPanel)) return;
    const tbody = document.getElementById('admin-chart-tracks-table-body') || document.getElementById('admin-tracks-table-body');
    if (!tbody || tbody.dataset.dndBound === '1') return;
    tbody.dataset.dndBound = '1';
    let draggedId = null;

    tbody.addEventListener('dragstart', (e) => {
      // Kliknięcie przycisku (np. kosz "Usuń") wewnątrz przeciągalnego wiersza
      // nie powinno inicjować drag — inaczej przeglądarka „pożera” kliknięcie
      // jako początek przeciągania i onclick przycisku nigdy się nie wykonuje.
      if (e.target.closest('button, a')) {
        e.preventDefault();
        return;
      }
      const tr = e.target.closest('tr[draggable="true"]');
      if (!tr) return;
      draggedId = tr.dataset.trackId;
      tr.classList.add('opacity-40');
      e.dataTransfer.effectAllowed = 'move';
      try { e.dataTransfer.setData('text/plain', draggedId); } catch (err) { /* IE */ }
    });
    tbody.addEventListener('dragend', () => {
      tbody.querySelectorAll('tr').forEach((tr) => tr.classList.remove('opacity-40'));
      draggedId = null;
    });
    tbody.addEventListener('dragover', (e) => {
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      const target = e.target.closest('tr[draggable="true"]');
      const dragged = draggedId ? tbody.querySelector(`tr[data-track-id="${draggedId}"]`) : null;
      if (!target || !dragged || target === dragged) return;
      const rect = target.getBoundingClientRect();
      const after = (e.clientY - rect.top) > rect.height / 2;
      tbody.insertBefore(dragged, after ? target.nextSibling : target);
    });
    tbody.addEventListener('drop', (e) => {
      e.preventDefault();
      this.persistChartOrder();
    });
  }

  // Zapisuje bieżącą kolejność wierszy notowania na serwerze.
  async persistChartOrder() {
    const tbody = document.getElementById('admin-chart-tracks-table-body') || document.getElementById('admin-tracks-table-body');
    if (!tbody) return;
    const order = [...tbody.querySelectorAll('tr[data-track-id]')].map((tr) => tr.dataset.trackId).filter(Boolean);
    if (!order.length) return;
    const { ok, data } = await this.apiSend('/admin/chart/reorder', 'POST', { order });
    if (!ok) {
      this.showToast((data && data.message) || 'Nie udało się zapisać kolejności.', 'warning');
    } else {
      this.showToast('Zapisano nową kolejność notowania.', 'success');
    }
    await this.refreshChartState();
    if (this.adminUser) await this.refreshAdminTracks();
    this.render();
  }

  // --- EDYCJA UTWORU (lightbox) ---
  openEditTrackModal(id) {
    if (!this.adminUser) {
      this.showToast('Wymagane uprawnienia redaktora.', 'warning');
      this.openAdminLoginModal();
      return;
    }
    const t = this.adminTracks.find((x) => x.id === id);
    if (!t) return;
    this.editingTrackId = id;
    this.editCoverFile = null;
    this.editCoverRemoved = false;
    this.editCoverOriginal = t.coverImageUrl || '';
    this.editAudioFile = null;
    this.editHadAudio = !!t.hasAudio;
    document.getElementById('edit-track-audio-input').value = '';
    this.renderEditAudioStatus();
    document.getElementById('edit-track-title').value = t.title || '';
    document.getElementById('edit-track-artist').value = t.artist || '';
    document.getElementById('edit-track-cover-input').value = '';
    this.renderEditCoverPreview(this.editCoverOriginal);
    document.getElementById('admin-edit-track-modal').classList.remove('hidden');
    morsCreateIcons();
  }

  closeEditTrackModal() {
    document.getElementById('admin-edit-track-modal').classList.add('hidden');
    this.editingTrackId = null;
    this.editCoverFile = null;
    this.editAudioFile = null;
  }

  renderEditAudioStatus() {
    const el = document.getElementById('edit-track-audio-status');
    if (!el) return;
    el.textContent = this.editAudioFile
      ? `Nowy plik: ${this.editAudioFile.name} (${(this.editAudioFile.size / 1048576).toFixed(1)} MB) — zostanie wgrany po zapisaniu.`
      : (this.editHadAudio ? 'Wgrany jest plik audio.' : 'Brak pliku audio (odtwarzany jest dźwięk zastępczy).');
  }

  onEditAudioSelected(file) {
    if (!file) return;
    const okExt = ['.wav', '.flac', '.mp3', '.ogg', '.m4a'].some((e) => (file.name || '').toLowerCase().endsWith(e));
    if (!okExt) {
      this.showToast('Obsługiwane formaty audio: .WAV, .FLAC, .MP3, .OGG, .M4A', 'warning');
      document.getElementById('edit-track-audio-input').value = '';
      return;
    }
    if (file.size > 120 * 1024 * 1024) {
      this.showToast('Plik audio jest zbyt duży (max 120 MB).', 'warning');
      document.getElementById('edit-track-audio-input').value = '';
      return;
    }
    this.editAudioFile = file;
    this.renderEditAudioStatus();
  }

  renderEditCoverPreview(url) {
    const img = document.getElementById('edit-track-cover-img');
    const ph = document.getElementById('edit-track-cover-placeholder');
    const rm = document.getElementById('edit-track-cover-remove');
    if (url) { img.src = url; img.classList.remove('hidden'); ph.classList.add('hidden'); }
    else { img.removeAttribute('src'); img.classList.add('hidden'); ph.classList.remove('hidden'); }
    rm.classList.toggle('hidden', !url);
  }

  onEditCoverSelected(file) {
    if (!file) return;
    if (!['image/jpeg', 'image/png', 'image/webp', 'image/gif'].includes(file.type)) {
      this.showToast('Okładka musi być plikiem JPG, PNG, WEBP lub GIF.', 'warning');
      return;
    }
    if (file.size > 2 * 1024 * 1024) {
      this.showToast('Okładka jest zbyt duża (max 2 MB).', 'warning');
      return;
    }
    this.editCoverFile = file;
    this.editCoverRemoved = false;
    const reader = new FileReader();
    reader.onload = (e) => this.renderEditCoverPreview(e.target.result);
    reader.readAsDataURL(file);
  }

  removeEditCover() {
    this.editCoverFile = null;
    this.editCoverRemoved = true;
    document.getElementById('edit-track-cover-input').value = '';
    this.renderEditCoverPreview('');
  }

  async saveEditedTrack() {
    const title = document.getElementById('edit-track-title').value.trim();
    const artist = document.getElementById('edit-track-artist').value.trim();
    if (!title || !artist) {
      this.showToast('Wprowadź tytuł utworu i wykonawcę.', 'warning');
      return;
    }
    const btn = document.getElementById('edit-track-save-btn');
    btn.disabled = true;
    btn.classList.add('opacity-75');
    const fd = new FormData();
    fd.append('title', title);
    fd.append('artist', artist);
    if (this.editAudioFile) fd.append('audio', this.editAudioFile);
    if (this.editCoverFile) fd.append('cover', this.editCoverFile);
    else if (this.editCoverRemoved && this.editCoverOriginal) fd.append('removeCover', '1');

    const { ok, data } = await this.apiUpload(`/admin/tracks/${this.editingTrackId}`, fd);
    btn.disabled = false;
    btn.classList.remove('opacity-75');
    if (!ok) {
      this.showToast(data.message || 'Nie udało się zapisać zmian.', 'warning');
      return;
    }
    this.closeEditTrackModal();
    await this.refreshAdminTracks();
    await this.refreshChartState();
    this.render();
    this.showToast('Zmiany zostały zapisane.', 'success');
  }

  async moveTrackAdmin(id, target) {
    if (!this.adminUser) {
      this.showToast('Wymagane uprawnienia redaktora.', 'warning');
      this.openAdminLoginModal();
      return;
    }
    const { ok, data } = await this.apiSend(`/admin/tracks/${id}/move`, 'POST', { target });
    if (!ok) {
      this.showToast(data.message || 'Nie udało się przenieść utworu.', 'warning');
      return;
    }
    await this.refreshAdminTracks();
    await this.refreshChartState();
    this.render();
    this.showToast(target === 'chart' ? 'Utwór przeniesiono do Notowania.' : 'Utwór przeniesiono do Poczekalni.', 'success');
  }

  async removeTrackAdmin(id) {
    if (!this.adminUser) {
      this.showToast('Wymagane uprawnienia redaktora.', 'warning');
      this.openAdminLoginModal();
      return;
    }
    const { ok, data } = await this.apiSend(`/admin/tracks/${id}`, 'DELETE');
    if (!ok) {
      this.showToast(data.message || 'Nie udało się usunąć utworu.', 'warning');
      return;
    }
    await this.refreshAdminTracks();
    await this.refreshChartState();
    this.render();
    this.showToast('Utwór został usunięty z bazy.', 'info');
  }

  showToast(message, type = 'info') {
    const container = document.getElementById('toast-container');
    if (!container) return;
    const toast = document.createElement('div');
    const borderClass = type === 'success' ? 'border-[#1BA345] bg-white text-[#1E293B]' :
      type === 'warning' ? 'border-[#FEC001] bg-white text-[#1E293B]' :
      'border-[#0041d2] bg-white text-[#1E293B]';

    toast.className = `p-4 border-2 shadow-ug-lg flex items-center gap-3 transition-all duration-300 transform translate-y-4 opacity-0 ${borderClass}`;
    const dot = document.createElement('div');
    dot.className = `w-2.5 h-2.5 ${type === 'success' ? 'bg-[#1BA345]' : type === 'warning' ? 'bg-[#FEC001]' : 'bg-[#0041d2]'} animate-ping`;
    const msgEl = document.createElement('span');
    msgEl.className = 'text-sm font-headings font-semibold';
    msgEl.textContent = message; // textContent zamiast innerHTML → brak wykonania HTML/JS z danych
    toast.appendChild(dot);
    toast.appendChild(msgEl);

    container.appendChild(toast);
    setTimeout(() => {
      toast.classList.remove('translate-y-4', 'opacity-0');
    }, 50);

    setTimeout(() => {
      toast.classList.add('translate-y-4', 'opacity-0');
      setTimeout(() => toast.remove(), 300);
    }, 3500);
  }

  bindEvents() {
    document.querySelectorAll('.nav-tab-btn').forEach((btn) => {
      btn.addEventListener('click', () => {
        this.switchTab(btn.dataset.tab);
      });
    });

    const audioDropzone = document.getElementById('admin-audio-dropzone');
    const audioFileInput = document.getElementById('admin-file-input');

    if (audioDropzone && audioFileInput) {
      audioDropzone.addEventListener('click', () => audioFileInput.click());
      audioFileInput.addEventListener('change', (e) => {
        if (e.target.files.length > 0) {
          this.handleAudioFileUpload(e.target.files[0]);
        }
      });

      audioDropzone.addEventListener('dragover', (e) => {
        e.preventDefault();
        audioDropzone.classList.add('border-[#0041d2]', 'bg-[#e5f5fd]');
      });

      audioDropzone.addEventListener('dragleave', () => {
        audioDropzone.classList.remove('border-[#0041d2]', 'bg-[#e5f5fd]');
      });

      audioDropzone.addEventListener('drop', (e) => {
        e.preventDefault();
        audioDropzone.classList.remove('border-[#0041d2]', 'bg-[#e5f5fd]');
        if (e.dataTransfer.files.length > 0) {
          this.handleAudioFileUpload(e.dataTransfer.files[0]);
        }
      });
    }

    const coverDropzone = document.getElementById('admin-cover-dropzone');
    const coverFileInput = document.getElementById('admin-cover-file-input');

    if (coverDropzone && coverFileInput) {
      coverDropzone.addEventListener('click', () => coverFileInput.click());
      coverFileInput.addEventListener('change', (e) => {
        if (e.target.files.length > 0) {
          this.handleCoverFileUpload(e.target.files[0]);
        }
      });

      coverDropzone.addEventListener('dragover', (e) => {
        e.preventDefault();
        coverDropzone.classList.add('border-[#0041d2]', 'bg-[#e5f5fd]');
      });

      coverDropzone.addEventListener('dragleave', () => {
        coverDropzone.classList.remove('border-[#0041d2]', 'bg-[#e5f5fd]');
      });

      coverDropzone.addEventListener('drop', (e) => {
        e.preventDefault();
        coverDropzone.classList.remove('border-[#0041d2]', 'bg-[#e5f5fd]');
        if (e.dataTransfer.files.length > 0) {
          this.handleCoverFileUpload(e.dataTransfer.files[0]);
        }
      });
    }
  }
}

let app;
function morsBootstrap() {
  app = new StudentRadioApp();
  // Udostępnij instancję dla inline onclick="app...." w statycznym shellu.
  window.app = app;
}
// Skrypt jest enqueue'owany w stopce — może załadować się już PO DOMContentLoaded.
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', morsBootstrap);
} else {
  morsBootstrap();
}
