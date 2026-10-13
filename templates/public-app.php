<?php
/**
 * Szablon publicznego shellu SPA "Lista Przebojów Radia MORS".
 * Statyczny DOM, do którego binduje assets/js/app.js (elementy po id:
 * chart-tracks-container, view-waiting, voting-drawer, vote-verify-modal, itd.).
 * Dołączany przez \Mors\Frontend\Shortcode::render().
 * Znaczniki <html>/<head>/<body>, CDN lucide oraz <script src="app.js">
 * są celowo pominięte — style/JS są enqueue'owane przez WordPress.
 *
 * @package RadioMors
 */
if (!defined('ABSPATH')) {
  exit;
}
?>
<div id="mors-app"
  class="mors-app bg-[#F5F5F5] text-[#1E293B] min-h-screen flex flex-col selection:bg-[#a1daf8] selection:text-[#00214d]">

  <!-- ================= TOP ON-AIR NOTIFICATION BAR ================= -->
  <aside aria-label="Informacja o audycji"
    class="mors-chrome bg-[#032c73] text-white border-b border-[#0041d2]/40 py-2 px-4 text-xs font-body">
    <div class="max-w-7xl mx-auto flex flex-wrap items-center justify-between gap-3">
      <div class="flex items-center gap-2.5">
        <span class="flex h-2.5 w-2.5 relative">
          <span class="animate-ping absolute inline-flex h-full w-full bg-[#EF305E] opacity-75"></span>
          <span class="relative inline-flex h-2.5 w-2.5 bg-[#EF305E]"></span>
        </span>
        <span
          class="font-headings font-bold text-white uppercase tracking-wider text-[11px] bg-[#EF305E] px-2 py-0.5">ON-AIR</span>
        <span class="text-[#a1daf8] hidden sm:inline">•</span>
        <span class="text-slate-200 hidden sm:inline">Audycja podsumowująca: <strong class="text-white font-semibold">w
            każdy Piątek o 18:00 na antenie Radia MORS</strong></span>
      </div>

      <div class="flex items-center gap-4 text-slate-200">
        <div id="live-pulse-badge" class="flex items-center gap-1.5 transition-transform duration-300">
          <i data-lucide="radio" class="w-3.5 h-3.5 text-[#a1daf8]"></i>
          <span>Głosów łącznie: <strong id="header-total-votes" class="text-white font-mono font-bold">0</strong></span>
        </div>
        <span class="hidden md:inline text-slate-400">•</span>
        <div class="hidden md:flex items-center gap-1.5 text-slate-200">
          <i data-lucide="clock" class="w-3.5 h-3.5 text-[#FEC001]"></i>
          <span>Koniec głosowania: <strong id="header-countdown"
              class="text-[#FEC001] font-mono font-bold">--</strong></span>
        </div>
      </div>
    </div>
  </aside>

  <!-- ================= MAIN HEADER & SUB-NAVIGATION ================= -->
  <header class="mors-chrome hidden bg-white border-b border-[#D9D9D9] sticky top-0 z-30 shadow-sm">
    <div
      class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-3.5 flex flex-col md:flex-row items-center justify-between gap-4">

      <div class="flex items-center gap-3">
        <div class="w-10 h-10 bg-[#0041d2] flex items-center justify-center text-white shadow-sm p-2 flex-shrink-0">
          <div class="flex items-end gap-0.5 h-4">
            <div class="w-1 bg-white ug-eq-1"></div>
            <div class="w-1 bg-white ug-eq-2"></div>
            <div class="w-1 bg-white ug-eq-3"></div>
            <div class="w-1 bg-white ug-eq-4"></div>
          </div>
        </div>
        <div>
          <div class="flex items-center gap-2">
            <h1 class="ug-h4 !text-xl !font-bold !text-[#032c73]">Lista Przebojów Radia MORS</h1>
            <span id="header-edition-num" class="ug-tag ug-tag-sail text-[11px] font-bold">#--</span>
          </div>
          <p class="ug-small !text-xs !text-[#647391]">OFICJALNE NOTOWANIE MUZYCZNE UNIWERSYTETU GDAŃSKIEGO</p>
        </div>
      </div>

      <nav class="flex flex-wrap items-center gap-1.5" aria-label="Główna nawigacja">
        <button data-tab="admin"
          class="nav-tab-btn px-3 sm:px-4 py-2 text-xs sm:text-sm font-headings font-semibold text-[#032c73] hover:bg-[#e5f5fd] hover:text-[#0041d2] border border-transparent transition-all flex items-center gap-2">
          <i data-lucide="shield-check" class="w-4 h-4 text-[#0041d2]"></i>
          <span>Panel Redaktora</span>
        </button>
      </nav>

    </div>
  </header>

  <!-- ================= 24H COOLDOWN STATUS BANNER ================= -->
  <section id="voter-lock-banner" aria-label="Status blokady 24h"
    class="hidden bg-[#FFFBEB] border-b border-[#FEC001] py-3.5 px-4 shadow-sm">
    <div class="max-w-7xl mx-auto flex flex-col sm:flex-row items-center justify-between gap-3 text-xs sm:text-sm">
      <div class="flex items-center gap-3">
        <div class="p-2 bg-[#FEC001]/20 text-[#B45309] border border-[#FEC001]/40">
          <i data-lucide="lock" class="w-5 h-5"></i>
        </div>
        <div>
          <span class="font-headings font-bold text-[#92400E]">Głosowałeś w ciągu ostatnich 24 godzin!</span>
          <p class="text-[#78350F] text-xs">Zgodnie z regulaminem radia możesz oddać 15 głosów raz na dobę. Kolejny głos
            za: <strong id="cooldown-timer-text" class="font-mono font-bold text-[#92400E]">--:--:--</strong></p>
        </div>
      </div>
      <div class="flex items-center gap-2">
        <button onclick="app.openSocialShareModal()" class="btn-ug-outlined btn-ug-sm !py-1.5 !px-3 !text-xs">
          <i data-lucide="image" class="w-3.5 h-3.5"></i> Karta głosu
        </button>
      </div>
    </div>
  </section>

  <!-- ================= MAIN CONTENT VIEWS ================= -->
  <main class="flex-grow max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8 pb-32">

    <!-- ===== PRZEŁĄCZNIK: NOTOWANIE / POCZEKALNIA (stały, poza widokami) ===== -->
    <div class="mb-6">
      <nav class="flex flex-wrap items-center gap-1.5" aria-label="Przełączanie widoku listy">
        <button data-tab="chart"
          class="nav-tab-btn px-4 py-2 text-xs sm:text-sm font-headings font-semibold transition-all flex items-center gap-2 bg-[#0041d2] text-white shadow-sm border border-[#0041d2]">
          <i data-lucide="list-ordered" class="w-4 h-4"></i>
          <span>Notowanie (<span class="mors-label-chart-top">TOP 25</span>)</span>
        </button>
        <button data-tab="waiting"
          class="nav-tab-btn px-4 py-2 text-xs sm:text-sm font-headings font-semibold text-[#032c73] hover:bg-[#e5f5fd] hover:text-[#0041d2] border border-transparent transition-all flex items-center gap-2">
          <i data-lucide="inbox" class="w-4 h-4"></i>
          <span>Poczekalnia <span id="waiting-count-badge" class="hidden sm:inline">(35)</span></span>
        </button>
      </nav>
    </div>

    <!-- ================= VIEW 1: GŁÓWNE NOTOWANIE (TOP 25) ================= -->
    <section id="view-chart" class="space-y-6">
      <div
        class="flex flex-col md:flex-row md:items-center justify-between gap-4 p-5 bg-white border border-[#D9D9D9] shadow-sm">
        <div>
          <h2 class="ug-h2 !text-2xl !font-bold !text-[#032c73] flex items-center gap-2">
            Główne Notowanie <span class="text-[#0041d2] mors-label-chart-top">TOP 25</span>
          </h2>
          <p class="ug-body !text-sm !text-[#647391] mt-1">
            Wybierz <strong>do 15 swoich faworytów</strong> (z notowania lub poczekalni). Głosowanie odnawia się co 24
            godziny.
          </p>
        </div>
      </div>

      <div id="chart-tracks-container"></div>
    </section>

    <!-- ================= VIEW 2: POCZEKALNIA ================= -->
    <section id="view-waiting" class="hidden space-y-6">
      <div class="p-6 md:p-8 bg-[#032c73] text-white border border-[#0041d2] shadow-md">
        <div class="flex flex-col lg:flex-row items-center justify-between gap-6">
          <div>
            <div
              class="inline-flex items-center gap-2 px-3 py-1 bg-[#0041d2] text-white text-xs font-headings font-semibold uppercase tracking-wider mb-2">
              <i data-lucide="sparkles" class="w-3.5 h-3.5 text-[#a1daf8]"></i> Poczekalnia do Listy Przebojów
            </div>
            <h2 class="ug-h2 !text-white !text-2xl md:!text-3xl mt-1"><span id="waiting-title-count">35</span> Nowych
              Propozycji</h2>
            <p class="text-slate-200 text-sm mt-1.5 max-w-2xl font-body">
              Utwory zgłoszone przez słuchaczy oraz redakcję muzyczną Radia MORS Uniwersytetu Gdańskiego. Utwory z
              największą liczbą głosów awansują bezpośrednio do Notowania Głównego w najbliższy piątek!
            </p>
          </div>
          <div class="flex items-center gap-3">
            <div class="text-center p-4 bg-[#00214d] border border-white/10 min-w-[120px]">
              <div id="waiting-total-count" class="text-3xl font-headings font-bold text-[#a1daf8]">35</div>
              <div class="text-[11px] text-slate-300 uppercase font-headings font-semibold mt-0.5">Utworów w puli</div>
            </div>
            <div class="text-center p-4 bg-[#00214d] border border-white/10 min-w-[120px]">
              <div id="waiting-promoted-count" class="text-3xl font-headings font-bold text-white">TOP 2</div>
              <div class="text-[11px] text-slate-300 uppercase font-headings font-semibold mt-0.5">Awans do <span
                  class="mors-label-chart-top">TOP 25</span></div>
            </div>
          </div>
        </div>
      </div>

      <div id="waiting-tracks-container"></div>
    </section>

    <!-- ================= VIEW 3: PANEL ADMINISTRATORA (CMS) ================= -->
    <section id="view-admin" class="hidden space-y-8">

      <!-- ===== PODSEKCJA: Utwory (Główna strona wtyczki) ===== -->
      <div id="admin-section-tracks"
        class="space-y-8 <?php echo (isset($mors_admin_section) && 'settings' === $mors_admin_section) ? 'hidden' : ''; ?>">

        <div class="p-6 ug-card space-y-5">
          <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
              <h3 class="ug-h4 !text-lg !font-bold !text-[#032c73] flex items-center gap-2">
                <i data-lucide="list-music" class="w-5 h-5 text-[#0041d2]"></i>
                <span>Zarządzanie utworami</span>
              </h3>
              <p class="ug-small !text-xs !text-[#647391] mt-1">
                Wszystkie utwory zgłoszone do Listy Przebojów z podziałem na Główne Notowanie oraz Poczekalnię.
              </p>
            </div>
            <button type="button" onclick="app.openAddTrackModal()" class="btn-ug-primary flex-shrink-0">
              <i data-lucide="plus-circle" class="w-4 h-4"></i>
              <span>Dodaj utwór</span>
            </button>
          </div>

          <!-- Pasek tabów: Notowanie / Poczekalnia -->
          <div class="flex items-center gap-2 border-b border-[#D9D9D9] pt-1">
            <button type="button" onclick="app.switchAdminTracksTab('chart')" id="admin-tracks-tab-btn-chart"
              class="admin-tracks-tab-btn px-4 py-2 text-xs sm:text-sm font-headings font-bold flex items-center gap-2 bg-[#0041d2] text-white shadow-sm border border-[#0041d2] transition-all">
              <i data-lucide="list-ordered" class="w-4 h-4"></i>
              <span>Notowanie (<span id="admin-tab-chart-count">0</span>)</span>
            </button>
            <button type="button" onclick="app.switchAdminTracksTab('waiting')" id="admin-tracks-tab-btn-waiting"
              class="admin-tracks-tab-btn px-4 py-2 text-xs sm:text-sm font-headings font-semibold flex items-center gap-2 text-[#032c73] hover:bg-[#e5f5fd] hover:text-[#0041d2] border border-transparent transition-all">
              <i data-lucide="inbox" class="w-4 h-4"></i>
              <span>Poczekalnia (<span id="admin-tab-waiting-count">0</span>)</span>
            </button>
          </div>

          <!-- TAB 1: Notowanie -->
          <div id="admin-tracks-panel-chart" class="space-y-4">
            <div
              class="flex items-center justify-between gap-2 p-3 bg-[#e5f5fd] border border-[#0041d2]/20 text-xs text-[#032c73]">
              <div class="flex items-center gap-2">
                <i data-lucide="info" class="w-4 h-4 text-[#0041d2] flex-shrink-0"></i>
                <span>Utwory z notowania możesz <strong>przeciągać</strong> (uchwyt ⋮⋮), aby zmienić ich kolejność na
                  liście. Nowa kolejność zapisuje się automatycznie.</span>
              </div>
            </div>

            <div class="overflow-x-auto">
              <table class="w-full text-left">
                <thead>
                  <tr class="border-b border-[#D9D9D9] text-[11px] text-[#647391] uppercase font-headings font-bold">
                    <th class="p-3 w-14 text-center">Poz.</th>
                    <th class="p-3">Tytuł</th>
                    <th class="p-3">Wykonawca</th>
                    <th class="p-3">Głosy</th>
                    <th class="p-3 text-right">Akcje</th>
                  </tr>
                </thead>
                <tbody id="admin-chart-tracks-table-body"></tbody>
              </table>
            </div>
          </div>

          <!-- TAB 2: Poczekalnia -->
          <div id="admin-tracks-panel-waiting" class="space-y-4 hidden">
            <div
              class="flex items-center justify-between gap-2 p-3 bg-[#e5f5fd] border border-[#0041d2]/20 text-xs text-[#032c73]">
              <div class="flex items-center gap-2">
                <i data-lucide="sparkles" class="w-4 h-4 text-[#0041d2] flex-shrink-0"></i>
                <span>Utwory w poczekalni są uporządkowane według liczby zdobytych głosów (od najwyższej liczby).</span>
              </div>
            </div>

            <div class="overflow-x-auto">
              <table class="w-full text-left">
                <thead>
                  <tr class="border-b border-[#D9D9D9] text-[11px] text-[#647391] uppercase font-headings font-bold">
                    <th class="p-3 w-14 text-center">Lp.</th>
                    <th class="p-3">Tytuł</th>
                    <th class="p-3">Wykonawca</th>
                    <th class="p-3">Głosy</th>
                    <th class="p-3 text-right">Akcje</th>
                  </tr>
                </thead>
                <tbody id="admin-waiting-tracks-table-body"></tbody>
              </table>
            </div>
          </div>

        </div>

      </div><!-- /#admin-section-tracks -->

      <!-- ===== PODSEKCJA: Dodaj utwór ===== -->
      <div id="admin-section-add-track"
        class="<?php echo (isset($mors_admin_section) && 'add-track' === $mors_admin_section) ? '' : 'hidden'; ?>">

        <!-- Dodaj nowy utwór (.WAV / .FLAC / .MP3) -->
        <div class="grid grid-cols-1 gap-6">
          <div class="p-6 ug-card space-y-5">
            <h3 class="ug-h4 !text-lg !font-bold !text-[#032c73] flex items-center gap-2">
              <i data-lucide="upload-cloud" class="w-5 h-5 text-[#0041d2]"></i>
              <span>Dodaj nowy utwór (.WAV / .FLAC / .MP3)</span>
            </h3>

            <div id="admin-audio-dropzone"
              class="border-2 border-dashed border-[#D9D9D9] hover:border-[#0041d2] p-6 text-center cursor-pointer transition-all bg-[#F5F5F5] group">
              <input type="file" id="admin-file-input"
                accept=".wav,.flac,.mp3,audio/wav,audio/x-wav,audio/flac,audio/x-flac,audio/mpeg,audio/mp3,audio/*"
                class="hidden" />
              <div
                class="w-12 h-12 bg-[#e5f5fd] text-[#0041d2] flex items-center justify-center mx-auto mb-3 group-hover:scale-105 transition-transform">
                <i data-lucide="music" class="w-6 h-6"></i>
              </div>
              <p class="text-sm font-headings font-semibold text-[#032c73]">Przeciągnij plik audio lub <span
                  class="text-[#0041d2] underline">wybierz z dysku</span></p>
              <p class="ug-small !text-xs !text-[#647391] mt-1">Obsługiwane formaty: <strong>.WAV</strong>,
                <strong>.FLAC</strong>, <strong>.MP3</strong>, <strong>.OGG</strong> lub <strong>.M4A</strong>.
                Max <?php echo esc_html( size_format( min( 120 * MB_IN_BYTES, wp_max_upload_size() ) ) ); ?>.
              </p>
            </div>

            <div id="admin-upload-status" class="hidden p-4 bg-[#e5f5fd] border border-[#0041d2]/30 space-y-2">
              <div class="flex items-center justify-between text-xs">
                <span class="text-[#0041d2] font-semibold flex items-center gap-1.5">
                  <i data-lucide="loader-2" class="w-3.5 h-3.5 animate-spin"></i> Wgrywanie pliku audio...
                </span>
                <span class="text-[#032c73] font-mono font-bold">100%</span>
              </div>
              <div class="w-full h-2 bg-[#D9D9D9] overflow-hidden">
                <div id="admin-upload-progress" class="h-full bg-[#0041d2] w-0 transition-all duration-300"></div>
              </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div class="ug-form-group">
                <label class="ug-form-label">Tytuł utworu</label>
                <input type="text" id="admin-input-title" placeholder="np. Bałtycki Sztorm" class="ug-input" />
              </div>
              <div class="ug-form-group">
                <label class="ug-form-label">Wykonawca / Zespół</label>
                <input type="text" id="admin-input-artist" placeholder="np. Neon Wave" class="ug-input" />
              </div>
              <div class="ug-form-group">
                <label class="ug-form-label">Docelowa sekcja</label>
                <select id="admin-input-target" class="ug-select">
                  <option value="waiting" id="upload-target-waiting">Poczekalnia (Uzupełnij do 35 utworów)</option>
                  <option value="chart" id="upload-target-chart">Notowanie Główne (TOP 25)</option>
                </select>
              </div>
              <div class="ug-form-group">
                <label class="ug-form-label">Czas trwania (min:sek)</label>
                <input type="text" id="admin-input-duration" value="3:30" class="ug-input" />
              </div>
            </div>

            <div class="p-4 bg-[#F5F5F5] border border-[#D9D9D9] space-y-3">
              <div class="flex items-center justify-between">
                <label class="ug-form-label !mb-0 flex items-center gap-1.5">
                  <i data-lucide="image" class="w-4 h-4 text-[#0041d2]"></i>
                  <span>Okładka utworu / singla (.JPG, .PNG, .WEBP)</span>
                </label>
                <span class="ug-small !text-[11px]">Format 1:1, Max 2 MB</span>
              </div>

              <div class="flex flex-col sm:flex-row items-center gap-4">
                <div
                  class="relative w-24 h-24 bg-[#00214d] flex items-center justify-center text-white border border-[#D9D9D9] flex-shrink-0 overflow-hidden shadow-sm">
                  <img id="admin-cover-preview-img" src="" alt="Podgląd okładki"
                    class="hidden w-full h-full object-cover" />
                  <div id="admin-cover-placeholder" class="text-center p-2">
                    <i data-lucide="disc" class="w-6 h-6 text-[#a1daf8] mx-auto mb-1"></i>
                    <span class="text-[9px] text-slate-300 font-headings block">Brak pliku</span>
                  </div>
                </div>

                <div id="admin-cover-dropzone"
                  class="flex-grow w-full border-2 border-dashed border-[#D9D9D9] hover:border-[#0041d2] p-4 text-center cursor-pointer transition-all bg-white group">
                  <input type="file" id="admin-cover-file-input" accept="image/jpeg,image/png,image/webp,image/jpg"
                    class="hidden" />
                  <p class="text-xs font-headings font-semibold text-[#032c73]">Kliknij lub upuść grafikę okładki</p>
                  <p class="ug-small !text-[11px] text-[#647391] mt-0.5">Automatyczne przycięcie do 600×600 px i
                    kompresja WebP</p>
                </div>

                <button type="button" id="admin-cover-remove-btn" onclick="app.removePendingCover()"
                  class="hidden btn-ug-outlined btn-ug-sm !text-[#EF305E] !border-[#EF305E]/40 hover:!bg-[#EF305E]/10"
                  title="Usuń wybraną okładkę">
                  <i data-lucide="trash-2" class="w-4 h-4"></i>
                </button>
              </div>
            </div>

            <div class="flex justify-end pt-2">
              <button onclick="app.submitNewTrackFromAdmin()" class="btn-ug-primary">
                <i data-lucide="plus-circle" class="w-4 h-4"></i>
                <span>Zapisz i dodaj do listy</span>
              </button>
            </div>
          </div>
        </div>

      </div><!-- /#admin-section-add-track -->

      <!-- ===== PODSEKCJA: Ustawienia listy ===== -->
      <?php
      $mors_current_ed = (new \Mors\Db\Editions_Repo())->current();
      $mors_cur_ed_num = ($mors_current_ed && isset($mors_current_ed['edition_number'])) ? (int) $mors_current_ed['edition_number'] : 1;
      ?>
      <div id="admin-section-settings"
        class="<?php echo (isset($mors_admin_section) && 'settings' === $mors_admin_section) ? '' : 'hidden'; ?>">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">

          <!-- Karta 1: Konfiguracja liczby pozycji -->
          <div class="p-6 ug-card space-y-5">
            <div class="flex items-center gap-2">
              <i data-lucide="sliders" class="w-5 h-5 text-[#0041d2]"></i>
              <h3 class="ug-h4 !text-lg !font-bold !text-[#032c73]">Konfiguracja liczby pozycji</h3>
            </div>
            <p class="ug-body !text-sm !text-[#647391]">
              Zdefiniuj numer bieżącego notowania, liczbę pozycji w Notowaniu i Poczekalni, awanse oraz dobowy limit
              głosów.
            </p>

            <div id="settings-save-feedback" class="hidden p-3 text-xs font-semibold"></div>

            <form id="settings-config-form" onsubmit="event.preventDefault(); app.saveSettings();" class="space-y-4">
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="ug-form-group">
                  <label class="ug-form-label" for="settings-input-edition-number">
                    Numer notowania
                  </label>
                  <input type="number" id="settings-input-edition-number" min="1" max="999999" class="ug-input"
                    value="<?php echo esc_attr($mors_cur_ed_num); ?>" required />
                  <span class="ug-small text-xs text-[#647391] mt-1 block">Bieżące wydanie (np. 1)</span>
                </div>

                <div class="ug-form-group">
                  <label class="ug-form-label" for="settings-input-chart-size">
                    Główne Notowanie (pozycje)
                  </label>
                  <input type="number" id="settings-input-chart-size" min="1" max="100" class="ug-input" value="25"
                    required />
                  <span class="ug-small text-xs text-[#647391] mt-1 block">Domyślnie: 25 utworów</span>
                </div>

                <div class="ug-form-group">
                  <label class="ug-form-label" for="settings-input-waiting-size">
                    Poczekalnia (liczba utworów)
                  </label>
                  <input type="number" id="settings-input-waiting-size" min="1" max="200" class="ug-input" value="35"
                    required />
                  <span class="ug-small text-xs text-[#647391] mt-1 block">Domyślnie: 35 w puli</span>
                </div>

                <div class="ug-form-group">
                  <label class="ug-form-label" for="settings-input-promoted-count">
                    Awans z Poczekalni
                  </label>
                  <input type="number" id="settings-input-promoted-count" min="0" max="20" class="ug-input" value="2"
                    required />
                  <span class="ug-small text-xs text-[#647391] mt-1 block">Ile utworów z TOP awansuje</span>
                </div>

                <div class="ug-form-group sm:col-span-2">
                  <label class="ug-form-label" for="settings-input-max-votes">
                    Głosy na słuchacza (24h)
                  </label>
                  <input type="number" id="settings-input-max-votes" min="1" max="50" class="ug-input" value="15"
                    required />
                  <span class="ug-small text-xs text-[#647391] mt-1 block">Limit głosów (domyślnie: 15)</span>
                </div>

                <div class="ug-form-group sm:col-span-2">
                  <label class="flex items-center gap-2 cursor-pointer" for="settings-input-voting-suspended">
                    <input type="checkbox" id="settings-input-voting-suspended" class="w-4 h-4" />
                    <span class="ug-form-label !mb-0">Zawieś głosowanie</span>
                  </label>
                  <span class="ug-small text-xs text-[#647391] mt-1 block">Po włączeniu słuchacze nie będą mogli oddawać głosów.</span>
                </div>
              </div>

              <div class="pt-2 flex justify-end">
                <button type="submit" id="settings-save-btn" class="btn-ug-primary text-xs sm:text-sm">
                  <i data-lucide="check" class="w-4 h-4"></i>
                  <span>Zapisz ustawienia</span>
                </button>
              </div>
            </form>
          </div>

          <!-- Karta 2: Informacje o edycji i reset -->
          <div class="p-6 ug-card space-y-5">
            <div class="flex items-center gap-2">
              <i data-lucide="settings" class="w-5 h-5 text-[#0041d2]"></i>
              <h3 class="ug-h4 !text-lg !font-bold !text-[#032c73]">Status bieżącego notowania</h3>
            </div>
            <p class="ug-body !text-sm !text-[#647391]">
              Aktualne podsumowanie otwartego notowania oraz opcja archiwizacji i zamknięcia wydania.
            </p>

            <div class="grid grid-cols-2 gap-3 text-xs font-body">
              <div class="p-3 bg-[#F5F5F5] border border-[#D9D9D9]">
                <div class="text-[#647391]">Notowanie</div>
                <strong id="settings-edition-num"
                  class="text-[#032c73] text-base font-headings font-bold">#<?php echo esc_html($mors_cur_ed_num); ?></strong>
              </div>
              <div class="p-3 bg-[#F5F5F5] border border-[#D9D9D9]">
                <div class="text-[#647391]">Status</div>
                <strong id="settings-edition-status" class="text-[#0041d2] font-semibold">--</strong>
              </div>
              <div class="p-3 bg-[#F5F5F5] border border-[#D9D9D9]">
                <div class="text-[#647391]">Koniec głosowania</div>
                <strong id="settings-edition-ends" class="text-[#032c73] font-mono font-semibold">--</strong>
              </div>
              <div class="p-3 bg-[#F5F5F5] border border-[#D9D9D9]">
                <div class="text-[#647391]">Głosów łącznie</div>
                <strong id="settings-edition-votes" class="text-[#032c73] font-mono font-semibold">0</strong>
              </div>
            </div>

            <div class="pt-4 border-t border-[#D9D9D9] space-y-3">
              <div>
                <div class="font-headings font-bold text-sm text-[#032c73]">Zamknięcie i reset notowania</div>
                <p class="ug-small !text-xs !text-[#647391] mt-0.5">Archiwizuje bieżące notowanie, promuje utwory z
                  poczekalni, zeruje głosy i otwiera nowe notowanie.</p>
              </div>
              <div class="flex justify-end pt-1">
                <button onclick="app.openResetChartModal()"
                  class="btn-ug-primary !bg-[#EF305E] !border-[#EF305E] hover:!bg-[#d9224e] !text-white text-xs sm:text-sm">
                  <i data-lucide="refresh-cw" class="w-4 h-4"></i>
                  <span>Zamknij i zresetuj notowanie</span>
                </button>
              </div>
            </div>
          </div>

        </div>
      </div><!-- /#admin-section-settings -->

    </section>

  </main>

  <!-- ================= STICKY FLOATING VOTING DRAWER ================= -->
  <aside id="voting-drawer" aria-label="Pasek oddawania głosu"
    class="fixed bottom-0 left-0 right-0 z-40 bg-white border-t border-[#0041d2]/30 px-4 py-3.5 shadow-ug-lg transition-transform duration-300 translate-y-full">
    <div class="max-w-7xl mx-auto flex flex-col sm:flex-row items-center justify-between gap-4">

      <div class="flex items-center gap-4 w-full sm:w-auto">
        <div class="relative flex-shrink-0">
          <div
            class="w-12 h-12 bg-[#0041d2] flex items-center justify-center font-headings font-bold text-white text-base shadow-sm">
            <span id="drawer-vote-count">0 / 15</span>
          </div>
        </div>
        <div class="min-w-0">
          <div class="flex items-center gap-2">
            <span class="font-headings font-bold text-[#032c73] text-sm">Twoja karta do głosowania</span>
            <span id="drawer-max-tag" class="ug-tag ug-tag-sail text-[10px] font-bold">Max 15 utworów</span>
          </div>
          <p class="ug-small !text-xs !text-[#647391] truncate">Wybierz jeszcze utwory lub zatwierdź swój głos na 24h.
          </p>
          <div class="w-48 h-2 bg-[#D9D9D9] mt-1.5 overflow-hidden">
            <div id="drawer-progress-fill" class="h-full bg-[#0041d2] transition-all duration-300 w-0"></div>
          </div>
        </div>
      </div>

      <div class="flex items-center gap-3 w-full sm:w-auto justify-end">
        <button onclick="app.openSubmitVoteModal()" id="drawer-submit-btn"
          class="btn-ug-primary w-full sm:w-auto !py-3 !px-6 !text-sm">
          <span>Zatwierdź swój głos (0/15)</span>
          <i data-lucide="arrow-right" class="w-4 h-4"></i>
        </button>
      </div>

    </div>
  </aside>

  <!-- ================= PERSISTENT MINI AUDIO PLAYER ================= -->


  <!-- ================= MODAL 0: LOGOWANIE ADMINISTRATORA / REDAKCJI ================= -->
  <div id="admin-login-modal" class="hidden fixed inset-0 z-50 ug-modal-backdrop flex items-center justify-center p-4">
    <div class="bg-white border-2 border-[#0041d2] p-6 sm:p-8 max-w-md w-full shadow-ug-lg space-y-5">

      <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
          <div class="p-2.5 bg-[#00214d] text-white">
            <i data-lucide="shield-check" class="w-6 h-6"></i>
          </div>
          <div>
            <h3 class="ug-h4 !text-lg !font-bold !text-[#032c73]">Logowanie Redakcji</h3>
            <p class="ug-small">Radio MORS • Uniwersytet Gdański</p>
          </div>
        </div>
        <button onclick="app.closeAdminLoginModal()" class="text-[#647391] hover:text-[#032c73] p-1">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <div class="space-y-3.5">
        <div class="ug-form-group">
          <label class="ug-form-label">Adres e-mail w domenie UG</label>
          <div class="relative">
            <input type="email" id="admin-login-email" placeholder="redakcja@mors.ug.edu.pl"
              value="redakcja@mors.ug.edu.pl" class="ug-input pl-9" />
            <i data-lucide="mail" class="w-4 h-4 text-[#647391] absolute left-3 top-3.5"></i>
          </div>
        </div>

        <div class="ug-form-group">
          <label class="ug-form-label">Hasło dostępu</label>
          <div class="relative">
            <input type="password" id="admin-login-password" placeholder="••••••••" value="RadioMORS2026!"
              class="ug-input pl-9" />
            <i data-lucide="key" class="w-4 h-4 text-[#647391] absolute left-3 top-3.5"></i>
          </div>
        </div>

        <div class="p-3 bg-[#e5f5fd] border border-[#0041d2]/30 flex items-center justify-between">
          <span class="ug-small !text-[11px]">Konto testowe redaktora:</span>
          <button type="button" onclick="app.fillDemoAdminCredentials()"
            class="text-xs font-headings font-bold text-[#0041d2] hover:underline">
            Uzupełnij dane demo
          </button>
        </div>
      </div>

      <div class="flex items-center justify-end gap-3 pt-2">
        <button onclick="app.closeAdminLoginModal()" class="btn-ug-outlined btn-ug-sm">
          Anuluj
        </button>
        <button onclick="app.submitAdminLogin()" class="btn-ug-primary">
          <i data-lucide="log-in" class="w-4 h-4"></i>
          <span>Zaloguj się</span>
        </button>
      </div>

    </div>
  </div>


  <!-- ================= MODAL 1: WERYFIKACJA & ZATWIERDZENIE GŁOSU ================= -->
  <div id="vote-verify-modal" class="hidden fixed inset-0 z-50 ug-modal-backdrop flex items-center justify-center p-4">
    <div class="bg-white border border-[#D9D9D9] p-6 sm:p-8 max-w-lg w-full shadow-ug-lg space-y-6">
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
          <div class="p-2.5 bg-[#e5f5fd] text-[#0041d2] border border-[#0041d2]/20">
            <i data-lucide="vote" class="w-6 h-6"></i>
          </div>
          <div>
            <h3 class="ug-h4 !text-lg !font-bold !text-[#032c73]">Potwierdź swój głos</h3>
            <p id="modal-edition-label" class="ug-small">Lista Przebojów Radia MORS</p>
          </div>
        </div>
        <button onclick="app.closeSubmitVoteModal()" class="text-[#647391] hover:text-[#032c73] p-1">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <div class="space-y-2">
        <label class="ug-form-label">Wybrane utwory:</label>
        <div id="modal-selected-tracks-list" class="space-y-2 max-h-48 overflow-y-auto"></div>
      </div>

      <div class="p-4 bg-[#F5F5F5] border border-[#D9D9D9] flex items-center justify-between">
        <div class="flex items-center gap-3">
          <input type="checkbox" id="turnstile-check" checked class="w-5 h-5 accent-[#0041d2] cursor-pointer" />
          <div>
            <div class="text-xs font-headings font-bold text-[#032c73] flex items-center gap-1.5">
              <span>Weryfikacja słuchacza</span>
              <i data-lucide="shield-check" class="w-3.5 h-3.5 text-[#1BA345]"></i>
            </div>
            <p class="ug-small !text-[11px]">Zabezpieczenie przed botami (Cloudflare Turnstile)</p>
          </div>
        </div>
        <div class="text-[10px] text-[#647391] font-mono">ID: 8f3e..2b1c</div>
      </div>

      <div class="p-3 bg-[#FFFBEB] border border-[#FEC001]/50 text-xs text-[#92400E] flex items-start gap-2">
        <i data-lucide="alert-triangle" class="w-4 h-4 flex-shrink-0 mt-0.5 text-[#B45309]"></i>
        <span>Po zatwierdzeniu Twój głos zostanie zapisany, a kolejna możliwość głosowania odblokuje się dokładnie za 24
          godziny.</span>
      </div>

      <div class="flex items-center justify-end gap-3 pt-2">
        <button onclick="app.closeSubmitVoteModal()"
          class="px-4 py-2.5 text-[#647391] hover:text-[#032c73] text-xs font-headings font-semibold">
          Anuluj
        </button>
        <button onclick="app.confirmAndCastVotes()" class="btn-ug-primary">
          <span>Zatwierdź i oddaj głos</span>
        </button>
      </div>
    </div>
  </div>

  <!-- ================= MODAL 2: SOCIAL MEDIA SHARE STUDIO ================= -->
  <div id="social-share-modal"
    class="hidden fixed inset-0 z-50 ug-modal-backdrop flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white border border-[#D9D9D9] p-6 sm:p-8 max-w-2xl w-full shadow-ug-lg space-y-6 my-8">

      <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
          <div class="p-2.5 bg-[#e5f5fd] text-[#0041d2] border border-[#0041d2]/20">
            <i data-lucide="share-2" class="w-6 h-6"></i>
          </div>
          <div>
            <h3 class="ug-h4 !text-lg !font-bold !text-[#032c73]">Centrum Udostępniania Wyników</h3>
            <p class="ug-small">Pokaż znajomym na kogo głosujesz w Radiu MORS!</p>
          </div>
        </div>
        <button onclick="app.closeSocialShareModal()" class="text-[#647391] hover:text-[#032c73] p-1">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <div class="flex flex-col sm:flex-row items-center gap-6">
        <div
          class="relative w-48 sm:w-56 h-[340px] sm:h-[390px] overflow-hidden shadow-md border-2 border-[#0041d2] flex-shrink-0 bg-[#00214d]">
          <canvas id="social-share-canvas" class="w-full h-full object-contain"></canvas>
          <div class="absolute bottom-2 right-2 px-2 py-0.5 bg-black/60 text-[9px] font-mono text-[#a1daf8]">
            1080x1920 HD
          </div>
        </div>

        <div class="space-y-3 w-full">
          <p class="ug-body !text-xs !text-[#647391]">
            Wygenerowaliśmy dedykowaną kartę graficzną w formacie <strong>Instagram Stories / TikTok / Facebook</strong>
            w oficjalnej kolorystyce Uniwersytetu Gdańskiego.
          </p>

          <button onclick="app.downloadSocialCard()" class="btn-ug-primary w-full !text-xs sm:!text-sm">
            <i data-lucide="download" class="w-4 h-4"></i> Pobierz grafikę (PNG)
          </button>

          <button onclick="app.shareViaWebShare()" class="btn-ug-outlined w-full !text-xs sm:!text-sm">
            <i data-lucide="share" class="w-4 h-4"></i> Udostępnij bezpośrednio
          </button>

          <div class="pt-2 border-t border-[#D9D9D9] text-[11px] text-[#647391] space-y-1">
            <p>• Otaguj nas: <strong class="text-[#0041d2]">@radiomors_ug</strong></p>
            <p>• Użyj hashtagu: <strong class="text-[#032c73]">#ListaPrzebojowMORS #UniwersytetGdański</strong></p>
          </div>
        </div>
      </div>

      <div class="flex justify-end">
        <button onclick="app.closeSocialShareModal()"
          class="px-5 py-2 bg-[#F5F5F5] hover:bg-[#D9D9D9] text-[#032c73] text-xs font-headings font-semibold">
          Zamknij
        </button>
      </div>

    </div>
  </div>

  <!-- ================= MODAL 3: POTWIERDZENIE RESETU NOTOWANIA (ADMIN) ================= -->
  <div id="admin-reset-modal" class="hidden fixed inset-0 z-50 ug-modal-backdrop flex items-center justify-center p-4">
    <div class="bg-white border border-[#EF305E]/40 p-6 sm:p-8 max-w-md w-full shadow-ug-lg space-y-5">
      <div class="flex items-center gap-3">
        <div class="p-3 bg-[#EF305E]/10 text-[#EF305E] border border-[#EF305E]/20">
          <i data-lucide="alert-octagon" class="w-6 h-6"></i>
        </div>
        <div>
          <h3 class="ug-h4 !text-lg !font-bold !text-[#032c73]">Zamknięcie i Reset Notowania</h3>
          <p class="ug-small !text-[#EF305E]">Operacja nieodwracalna</p>
        </div>
      </div>

      <div class="ug-body !text-xs !text-[#647391] space-y-2">
        <p>Ta operacja wykona następujące kroki:</p>
        <ul class="list-disc pl-4 space-y-1 text-[#1E293B]">
          <li>Zliczy ostateczne głosy w bieżącym notowaniu.</li>
          <li>Wyliczy nowe pozycje (awans TOP 2 z Poczekalni do Notowania).</li>
          <li>Zarchiwizuje wyniki bieżącego wydania.</li>
          <li>Zresetuje głosy do 0 i otworzy kolejne notowanie.</li>
          <li>Odblokuje możliwość ponownego głosowania dla wszystkich słuchaczy.</li>
        </ul>
      </div>

      <div class="flex items-center justify-end gap-3 pt-2">
        <button onclick="app.closeResetChartModal()"
          class="px-4 py-2 text-[#647391] hover:text-[#032c73] text-xs font-headings font-semibold">
          Anuluj
        </button>
        <button onclick="app.executeResetChart()"
          class="btn-ug-primary !bg-[#EF305E] !border-[#EF305E] hover:!bg-[#d9224e]">
          <i data-lucide="check" class="w-4 h-4"></i> Potwierdź i zresetuj
        </button>
      </div>
    </div>
  </div>

  <!-- Lightbox: dodawanie utworu -->
  <div id="admin-add-track-modal" class="hidden fixed inset-0 z-50 ug-modal-backdrop flex items-center justify-center p-4 overflow-y-auto">
    <form class="bg-white border border-[#D9D9D9] p-6 sm:p-8 max-w-lg w-full shadow-ug-lg space-y-5 my-auto"
      onsubmit="event.preventDefault(); app.saveNewTrack();">
      <div class="flex items-center justify-between gap-3">
        <h3 class="ug-h4 !text-lg !font-bold !text-[#032c73] flex items-center gap-2">
          <i data-lucide="plus-circle" class="w-5 h-5 text-[#0041d2]"></i>
          <span>Dodaj utwór</span>
        </h3>
        <button type="button" onclick="app.closeAddTrackModal()" class="text-[#647391] hover:text-[#032c73] p-1" title="Zamknij">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <div class="ug-form-group">
        <label class="ug-form-label" for="add-track-title">Tytuł</label>
        <input type="text" id="add-track-title" class="ug-input" required />
      </div>
      <div class="ug-form-group">
        <label class="ug-form-label" for="add-track-artist">Wykonawca</label>
        <input type="text" id="add-track-artist" class="ug-input" required />
      </div>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div class="ug-form-group">
          <label class="ug-form-label" for="add-track-target">Docelowa sekcja</label>
          <select id="add-track-target" class="ug-select">
            <option value="waiting">Poczekalnia</option>
            <option value="chart">Notowanie Główne</option>
          </select>
        </div>
        <div class="ug-form-group">
          <label class="ug-form-label" for="add-track-duration">Czas trwania (min:sek)</label>
          <input type="text" id="add-track-duration" value="3:30" class="ug-input" />
        </div>
      </div>

      <div class="p-4 bg-[#F5F5F5] border border-[#D9D9D9] space-y-2">
        <label class="ug-form-label !mb-0">Plik audio (.WAV, .FLAC, .MP3, .OGG, .M4A) — opcjonalnie</label>
        <p id="add-track-audio-status" class="ug-small !text-xs text-[#647391]">Nie wybrano pliku.</p>
        <p class="ug-small !text-[11px] text-[#647391]">Max <?php echo esc_html( size_format( min( 120 * MB_IN_BYTES, wp_max_upload_size() ) ) ); ?>.</p>
        <input type="file" id="add-track-audio-input" accept=".wav,.flac,.mp3,.ogg,.m4a,audio/*" class="hidden"
          onchange="app.onAddAudioSelected(this.files[0])" />
        <button type="button" onclick="document.getElementById('add-track-audio-input').click()" class="btn-ug-outlined btn-ug-sm">
          <i data-lucide="music" class="w-4 h-4"></i><span>Wybierz plik audio</span>
        </button>
      </div>

      <div class="p-4 bg-[#F5F5F5] border border-[#D9D9D9] space-y-3">
        <label class="ug-form-label !mb-0">Okładka (.JPG, .PNG, .WEBP, max 2 MB) — opcjonalnie</label>
        <div class="flex items-center gap-4">
          <div class="w-20 h-20 bg-[#00214d] flex items-center justify-center overflow-hidden flex-shrink-0 border border-[#D9D9D9]">
            <img id="add-track-cover-img" src="" alt="Okładka" class="hidden w-full h-full object-cover" />
            <i id="add-track-cover-placeholder" data-lucide="music" class="w-6 h-6 text-white"></i>
          </div>
          <div class="flex flex-col gap-2">
            <input type="file" id="add-track-cover-input" accept="image/jpeg,image/png,image/webp,image/gif" class="hidden"
              onchange="app.onAddCoverSelected(this.files[0])" />
            <button type="button" onclick="document.getElementById('add-track-cover-input').click()" class="btn-ug-outlined btn-ug-sm">
              <i data-lucide="upload" class="w-4 h-4"></i><span>Wybierz okładkę</span>
            </button>
            <button type="button" id="add-track-cover-remove" onclick="app.onAddCoverSelected(null)"
              class="hidden btn-ug-outlined btn-ug-sm !text-[#EF305E] !border-[#EF305E]/40 hover:!bg-[#EF305E]/10">
              <i data-lucide="trash-2" class="w-4 h-4"></i><span>Usuń okładkę</span>
            </button>
          </div>
        </div>
      </div>

      <div class="flex items-center justify-end gap-3 pt-2">
        <button type="button" onclick="app.closeAddTrackModal()"
          class="px-4 py-2 text-[#647391] hover:text-[#032c73] text-xs font-headings font-semibold">Anuluj</button>
        <button type="submit" id="add-track-save-btn" class="btn-ug-primary">
          <i data-lucide="check" class="w-4 h-4"></i><span>Dodaj utwór</span>
        </button>
      </div>
    </form>
  </div>

  <!-- Lightbox: edycja utworu -->
  <div id="admin-edit-track-modal" class="hidden fixed inset-0 z-50 ug-modal-backdrop flex items-center justify-center p-4 overflow-y-auto">
    <form class="bg-white border border-[#D9D9D9] p-6 sm:p-8 max-w-lg w-full shadow-ug-lg space-y-5"
      onsubmit="event.preventDefault(); app.saveEditedTrack();">
      <div class="flex items-center justify-between gap-3">
        <h3 class="ug-h4 !text-lg !font-bold !text-[#032c73] flex items-center gap-2">
          <i data-lucide="pencil" class="w-5 h-5 text-[#0041d2]"></i>
          <span>Edytuj utwór</span>
        </h3>
        <button type="button" onclick="app.closeEditTrackModal()" class="text-[#647391] hover:text-[#032c73] p-1" title="Zamknij">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <div class="ug-form-group">
        <label class="ug-form-label" for="edit-track-title">Tytuł</label>
        <input type="text" id="edit-track-title" class="ug-input" required />
      </div>
      <div class="ug-form-group">
        <label class="ug-form-label" for="edit-track-artist">Wykonawca</label>
        <input type="text" id="edit-track-artist" class="ug-input" required />
      </div>

      <div class="p-4 bg-[#F5F5F5] border border-[#D9D9D9] space-y-3">
        <label class="ug-form-label !mb-0">Okładka (.JPG, .PNG, .WEBP, max 2 MB)</label>
        <div class="flex items-center gap-4">
          <div class="w-20 h-20 bg-[#00214d] flex items-center justify-center overflow-hidden flex-shrink-0 border border-[#D9D9D9]">
            <img id="edit-track-cover-img" src="" alt="Okładka" class="hidden w-full h-full object-cover" />
            <i id="edit-track-cover-placeholder" data-lucide="music" class="w-6 h-6 text-white"></i>
          </div>
          <div class="flex flex-col gap-2">
            <input type="file" id="edit-track-cover-input" accept="image/jpeg,image/png,image/webp,image/gif" class="hidden"
              onchange="app.onEditCoverSelected(this.files[0])" />
            <button type="button" onclick="document.getElementById('edit-track-cover-input').click()"
              class="btn-ug-outlined btn-ug-sm">
              <i data-lucide="upload" class="w-4 h-4"></i><span>Zmień okładkę</span>
            </button>
            <button type="button" id="edit-track-cover-remove" onclick="app.removeEditCover()"
              class="hidden btn-ug-outlined btn-ug-sm !text-[#EF305E] !border-[#EF305E]/40 hover:!bg-[#EF305E]/10">
              <i data-lucide="trash-2" class="w-4 h-4"></i><span>Usuń okładkę</span>
            </button>
          </div>
        </div>
      </div>

      <div class="p-4 bg-[#F5F5F5] border border-[#D9D9D9] space-y-2">
        <label class="ug-form-label !mb-0">Plik audio (.WAV, .FLAC, .MP3, .OGG, .M4A)</label>
        <p id="edit-track-audio-status" class="ug-small !text-xs text-[#647391]"></p>
        <input type="file" id="edit-track-audio-input"
          accept=".wav,.flac,.mp3,.ogg,.m4a,audio/*" class="hidden"
          onchange="app.onEditAudioSelected(this.files[0])" />
        <button type="button" onclick="document.getElementById('edit-track-audio-input').click()"
          class="btn-ug-outlined btn-ug-sm">
          <i data-lucide="upload" class="w-4 h-4"></i><span>Podmień plik audio</span>
        </button>
      </div>

      <div class="flex items-center justify-end gap-3 pt-2">
        <button type="button" onclick="app.closeEditTrackModal()"
          class="px-4 py-2 text-[#647391] hover:text-[#032c73] text-xs font-headings font-semibold">Anuluj</button>
        <button type="submit" id="edit-track-save-btn" class="btn-ug-primary">
          <i data-lucide="check" class="w-4 h-4"></i><span>Zapisz zmiany</span>
        </button>
      </div>
    </form>
  </div>

  <!-- Toast Notification Container -->
  <div id="toast-container" class="fixed top-5 right-5 z-50 flex flex-col gap-2 max-w-sm w-full pointer-events-none">
  </div>

</div>