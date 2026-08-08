(function () {
  "use strict";

  const STATUSES = ["offen", "inBearbeitung", "abgeschlossen"];
  const STATUS_LABELS = {
    offen: "Offen",
    inBearbeitung: "In Bearbeitung",
    abgeschlossen: "Abgeschlossen",
  };
  const PAGE_SIZE = 30;

  const state = {
    baseUrl: localStorage.getItem("sola_api_base") || "/api",
    token: localStorage.getItem("sola_api_token") || "",
    filter: "",
    search: "",
    offset: 0,
    total: 0,
    orders: [],
    loading: false,
    detailCache: {},
  };

  const els = {
    settingsScreen: document.getElementById("settings-screen"),
    listScreen: document.getElementById("list-screen"),
    filters: document.getElementById("filters"),
    orderList: document.getElementById("order-list"),
    loadMoreBtn: document.getElementById("load-more-btn"),
    emptyState: document.getElementById("empty-state"),
    spinner: document.getElementById("spinner"),
    settingsBtn: document.getElementById("settings-btn"),
    connectBtn: document.getElementById("connect-btn"),
    inputBaseUrl: document.getElementById("input-base-url"),
    inputToken: document.getElementById("input-token"),
    settingsError: document.getElementById("settings-error"),
    toast: document.getElementById("toast"),
    contactModal: document.getElementById("contact-modal"),
    modalBody: document.getElementById("modal-body"),
    modalCloseBtn: document.getElementById("modal-close-btn"),
    bottomNav: document.getElementById("bottom-nav"),
    headerTitle: document.getElementById("header-title"),
    pickingScreen: document.getElementById("picking-screen"),
    pickingTableBody: document.getElementById("picking-table-body"),
    pickingSummary: document.getElementById("picking-summary"),
    presaleScreen: document.getElementById("presale-screen"),
    presaleListSelect: document.getElementById("presale-list-select"),
    presaleCampaigns: document.getElementById("presale-campaigns"),
    presaleTableBody: document.getElementById("presale-table-body"),
    presaleEmpty: document.getElementById("presale-empty"),
    presaleHint: document.getElementById("presale-hint"),
    presalePrintMeta: document.getElementById("presale-print-meta"),
    presalePrintBtn: document.getElementById("presale-print-btn"),
    pickingPrintMeta: document.getElementById("picking-print-meta"),
    pickingEmpty: document.getElementById("picking-empty"),
    printBtn: document.getElementById("print-btn"),
    searchBar: document.getElementById("search-bar"),
    searchInput: document.getElementById("search-input"),
  };

  let currentView = "orders";

  function showToast(message, isError) {
    els.toast.textContent = message;
    els.toast.className = isError ? "show error" : "show";
    clearTimeout(showToast._t);
    showToast._t = setTimeout(() => {
      els.toast.className = "";
    }, 2600);
  }

  function hasConfig() {
    return !!(state.baseUrl && state.token);
  }

  function openSettings(prefill) {
    els.inputBaseUrl.value = state.baseUrl || "/api";
    els.inputToken.value = prefill ? state.token : "";
    els.settingsError.textContent = "";
    els.settingsScreen.hidden = false;
    els.listScreen.hidden = true;
    els.filters.hidden = true;
    els.searchBar.hidden = true;
    els.pickingScreen.hidden = true;
    els.bottomNav.hidden = true;
    els.spinner.hidden = true;
  }

  function closeSettings() {
    els.settingsScreen.hidden = true;
    els.bottomNav.hidden = false;
    switchView(currentView);
  }

  function switchView(view) {
    currentView = view;
    document.querySelectorAll(".nav-btn").forEach((btn) => {
      btn.classList.toggle("active", btn.dataset.view === view);
    });

    const isOrders = view === "orders";
    const isPicking = view === "picking";
    const isPresale = view === "presale";

    els.listScreen.hidden = !isOrders;
    els.filters.hidden = !isOrders;
    els.searchBar.hidden = !isOrders;
    els.pickingScreen.hidden = !isPicking;
    els.presaleScreen.hidden = !isPresale;

    if (isOrders) {
      els.headerTitle.textContent = "Bestellungen";
      els.spinner.hidden = true;
    } else if (isPicking) {
      els.headerTitle.textContent = "Kommissionierung";
      loadPickingList();
    } else {
      els.headerTitle.textContent = "Vorverkauf";
      loadPreSaleGroups();
    }
  }

  document.querySelectorAll(".nav-btn").forEach((btn) => {
    btn.addEventListener("click", () => switchView(btn.dataset.view));
  });

  async function loadPickingList() {
    els.spinner.hidden = false;
    els.pickingTableBody.innerHTML = "";
    els.pickingEmpty.hidden = true;

    try {
      const data = await api("/picking-list?status=inBearbeitung");
      const now = new Date();
      const generated = "Erstellt: " + fmtDate(now.toISOString().slice(0, 19).replace("T", " "));
      els.pickingSummary.textContent = data.items.length + " Artikel aus " + data.orderCount + " Bestellungen in Bearbeitung";
      els.pickingPrintMeta.textContent = generated + " · " + data.orderCount + " Bestellungen in Bearbeitung";

      els.pickingTableBody.innerHTML = data.items
        .map(
          (item) =>
            "<tr><td>" +
            escapeHtml(item.productTitle) +
            '</td><td class="variant-cell">' +
            escapeHtml(item.variantTitle || "") +
            '</td><td class="qty-col">' +
            item.quantity +
            "x</td></tr>"
        )
        .join("");

      els.pickingEmpty.hidden = data.items.length > 0;
    } catch (e) {
      showToast(e.message, true);
    } finally {
      els.spinner.hidden = true;
    }
  }

  els.printBtn.addEventListener("click", () => window.print());
  els.presalePrintBtn.addEventListener("click", () => window.print());

  // --- Vorverkauf -----------------------------------------------------------

  let presaleGroups = null;

  // Lädt einmalig die Warengruppen, die überhaupt Vorverkäufe haben.
  async function loadPreSaleGroups() {
    if (presaleGroups) {
      loadPreSaleReport(els.presaleListSelect.value);
      return;
    }
    els.spinner.hidden = false;
    try {
      const data = await api("/presales");
      presaleGroups = data.groups || [];

      if (!presaleGroups.length) {
        els.presaleEmpty.textContent = "Es sind keine Vorverkäufe angelegt.";
        els.presaleEmpty.hidden = false;
        els.presaleCampaigns.innerHTML = "";
        els.presaleTableBody.innerHTML = "";
        return;
      }

      els.presaleListSelect.innerHTML = presaleGroups
        .map(
          (g) =>
            '<option value="' +
            g.productListId +
            '">' +
            escapeHtml(g.productList) +
            " (" +
            g.campaigns.length +
            (g.campaigns.length === 1 ? " Vorverkauf)" : " Vorverkäufe)") +
            "</option>"
        )
        .join("");

      loadPreSaleReport(presaleGroups[0].productListId);
    } catch (e) {
      showToast(e.message, true);
    } finally {
      els.spinner.hidden = true;
    }
  }

  els.presaleListSelect.addEventListener("change", () =>
    loadPreSaleReport(els.presaleListSelect.value)
  );

  // Auswertung einer Warengruppe über alle ihre Vorverkäufe hinweg.
  async function loadPreSaleReport(listId) {
    if (!listId) return;
    els.spinner.hidden = false;
    els.presaleEmpty.hidden = true;

    try {
      const data = await api("/presales/productlist/" + listId);
      const campaigns = data.campaigns || [];
      const variants = data.variants || [];

      els.presaleCampaigns.innerHTML = campaigns
        .map((c) => {
          const pct = Math.min(c.soldPercentage, 100);
          return (
            '<div class="presale-campaign' +
            (c.active ? " is-active" : "") +
            '">' +
            '<div class="presale-campaign-head">' +
            '<span class="presale-campaign-title">' +
            escapeHtml(c.title) +
            (c.active ? ' <span class="badge-live">läuft</span>' : "") +
            "</span>" +
            '<span class="presale-campaign-meta">' +
            fmtDateShort(c.start) +
            (c.end ? " – " + fmtDateShort(c.end) : "") +
            "</span>" +
            "</div>" +
            '<div class="presale-bar" title="' +
            c.sold +
            " von " +
            c.startInventory +
            ' Stück verkauft"><div class="presale-bar-fill' +
            (c.thresholdReached ? " reached" : "") +
            '" style="width:' +
            pct +
            '%"></div>' +
            '<div class="presale-bar-threshold" style="left:' +
            c.endPercentage +
            '%" title="Endet bei ' +
            c.endPercentage +
            '%"></div></div>' +
            '<div class="presale-campaign-foot">' +
            c.sold +
            " / " +
            c.startInventory +
            " Stk · " +
            c.soldPercentage +
            "% · Ende bei " +
            c.endPercentage +
            "% · " +
            c.orderCount +
            (c.orderCount === 1 ? " Bestellung" : " Bestellungen") +
            (c.thresholdReached ? ' · <span class="reached-note">Schwelle erreicht</span>' : "") +
            "</div>" +
            "</div>"
          );
        })
        .join("");

      const t = data.totals || {};
      els.presaleHint.textContent =
        "Aufsteigend nach Verkaufsquote — oben stehen die Ladenhüter. " +
        "Die Quote bezieht sich auf den Anfangsbestand je Vorverkauf, gerechnet über " +
        (t.campaigns || campaigns.length) +
        (campaigns.length === 1 ? " Vorverkauf." : " Vorverkäufe.");
      els.presalePrintMeta.textContent =
        escapeHtml(data.productList.title) +
        " · " +
        campaigns.length +
        " Vorverkäufe · Erstellt: " +
        fmtDate(new Date().toISOString().slice(0, 19).replace("T", " "));

      els.presaleTableBody.innerHTML = variants
        .map((v) => {
          const q = v.soldPercentage === null ? "—" : v.soldPercentage + "%";
          let cls = "";
          if (v.soldPercentage !== null) {
            if (v.soldPercentage < 25) cls = "quota-low";
            else if (v.soldPercentage < 60) cls = "quota-mid";
            else cls = "quota-high";
          }
          return (
            "<tr>" +
            "<td>" + escapeHtml(v.product) + "</td>" +
            '<td class="variant-cell">' + escapeHtml(v.variant) + "</td>" +
            '<td class="qty-col">' + v.startInventory + "</td>" +
            '<td class="qty-col">' + v.sold + "</td>" +
            '<td class="qty-col ' + cls + '">' + q + "</td>" +
            "</tr>"
          );
        })
        .join("");

      els.presaleEmpty.hidden = variants.length > 0;
      if (!variants.length) {
        els.presaleEmpty.textContent = "Für diese Warengruppe gibt es keine Varianten.";
      }
    } catch (e) {
      showToast(e.message, true);
    } finally {
      els.spinner.hidden = true;
    }
  }

  function fmtDateShort(d) {
    if (!d) return "";
    const parts = String(d).slice(0, 10).split("-");
    if (parts.length !== 3) return d;
    return parts[2] + "." + parts[1] + "." + parts[0];
  }

  async function api(path, options) {
    options = options || {};
    const headers = Object.assign({ "X-Api-Token": state.token }, options.headers || {});
    const res = await fetch(state.baseUrl.replace(/\/$/, "") + path, {
      method: options.method || "GET",
      headers: options.body ? Object.assign(headers, { "Content-Type": "application/json" }) : headers,
      body: options.body ? JSON.stringify(options.body) : undefined,
    });
    let data = null;
    try {
      data = await res.json();
    } catch (e) {
      // no body
    }
    if (!res.ok) {
      const message = (data && data.error) || ("Fehler " + res.status);
      throw new Error(message);
    }
    return data;
  }

  function fmtDate(s) {
    if (!s) return "";
    const d = new Date(s.replace(" ", "T"));
    if (isNaN(d.getTime())) return s;
    const pad = (n) => String(n).padStart(2, "0");
    return pad(d.getDate()) + "." + pad(d.getMonth() + 1) + "." + d.getFullYear() + " " + pad(d.getHours()) + ":" + pad(d.getMinutes());
  }

  async function refreshCounts() {
    try {
      const [all, offen, inBearbeitung, abgeschlossen] = await Promise.all([
        api("/orders?limit=1"),
        api("/orders?limit=1&status=offen"),
        api("/orders?limit=1&status=inBearbeitung"),
        api("/orders?limit=1&status=abgeschlossen"),
      ]);
      document.getElementById("count-all").textContent = all.total;
      document.getElementById("count-offen").textContent = offen.total;
      document.getElementById("count-inBearbeitung").textContent = inBearbeitung.total;
      document.getElementById("count-abgeschlossen").textContent = abgeschlossen.total;
    } catch (e) {
      // counts are a nice-to-have, ignore failures here
    }
  }

  async function loadOrders(reset) {
    if (state.loading) return;
    state.loading = true;
    if (reset) {
      state.offset = 0;
      state.orders = [];
      els.orderList.innerHTML = "";
    }
    els.spinner.hidden = false;
    els.loadMoreBtn.hidden = true;

    try {
      const qs = new URLSearchParams({ limit: PAGE_SIZE, offset: state.offset });
      if (state.filter) qs.set("status", state.filter);
      if (state.search) qs.set("q", state.search);
      const data = await api("/orders?" + qs.toString());
      state.total = data.total;
      state.orders = state.orders.concat(data.orders);
      state.offset += data.orders.length;
      renderList();
      els.loadMoreBtn.hidden = state.offset >= state.total;
      els.emptyState.hidden = state.orders.length > 0;
    } catch (e) {
      showToast(e.message, true);
    } finally {
      state.loading = false;
      els.spinner.hidden = true;
    }
  }

  function renderList() {
    els.orderList.innerHTML = state.orders.map(renderCard).join("");
    state.orders.forEach((order) => {
      const card = document.getElementById("card-" + order.id);
      if (!card) return;
      card.querySelectorAll(".status-btn").forEach((btn) => {
        btn.addEventListener("click", () => handleStatusChange(order, btn.dataset.status));
      });
      const toggle = card.querySelector(".products-toggle");
      if (toggle) {
        toggle.addEventListener("click", () => toggleProducts(order, card));
      }
      const customerBtn = card.querySelector(".customer-link");
      if (customerBtn) {
        customerBtn.addEventListener("click", () => openContactModal(order));
      }
      const notesBtn = card.querySelector(".notes-btn");
      if (notesBtn) {
        notesBtn.addEventListener("click", () => openNotesModal(order));
      }
    });
  }

  function openNotesModal(order) {
    els.modalBody.innerHTML =
      '<div class="contact-name">Anmerkung</div>' +
      '<div class="notes-full">' +
      escapeHtml(order.additionalNotes) +
      "</div>";
    els.contactModal.hidden = false;
  }

  function openContactModal(order) {
    const c = order.customer;
    const customerName = [c.firstName, c.surname].filter(Boolean).join(" ") || "Unbekannt";
    const addressLines = [
      c.street,
      [c.zip, c.city].filter(Boolean).join(" "),
    ].filter(Boolean);

    const subject = encodeURIComponent("Bestellung #" + order.id);
    const phoneHref = c.phone ? "tel:" + c.phone.replace(/[^0-9+]/g, "") : null;
    const mailHref = c.email ? "mailto:" + encodeURIComponent(c.email) + "?subject=" + subject : null;

    let actions = "";
    if (phoneHref) {
      actions +=
        '<a class="contact-action" href="' +
        phoneHref +
        '"><span class="icon czi-phone"></span><span>' +
        escapeHtml(formatPhoneDisplay(c.phone)) +
        '<span class="label-sub">Anrufen</span></span></a>';
    }
    if (mailHref) {
      actions +=
        '<a class="contact-action" href="' +
        mailHref +
        '"><span class="icon czi-mail"></span><span>' +
        escapeHtml(c.email) +
        '<span class="label-sub">E-Mail zu Bestellung #' +
        order.id +
        "</span></span></a>";
    }
    if (!actions) {
      actions = '<div class="contact-none">Keine Telefonnummer oder E-Mail hinterlegt.</div>';
    }

    els.modalBody.innerHTML =
      '<div class="contact-name">' +
      escapeHtml(customerName) +
      "</div>" +
      (c.company ? '<div class="contact-company">' + escapeHtml(c.company) + "</div>" : "") +
      (addressLines.length ? '<div class="contact-address">' + addressLines.map(escapeHtml).join("<br>") + "</div>" : "") +
      actions;

    els.contactModal.hidden = false;
  }

  function closeContactModal() {
    els.contactModal.hidden = true;
  }

  els.modalCloseBtn.addEventListener("click", closeContactModal);
  els.contactModal.addEventListener("click", (e) => {
    if (e.target === els.contactModal) closeContactModal();
  });
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape") closeContactModal();
  });

  function renderCard(order) {
    const customerName = [order.customer.firstName, order.customer.surname].filter(Boolean).join(" ") || "Unbekannt";
    const location = [order.customer.zip, order.customer.city].filter(Boolean).join(" ");
    const notes = order.additionalNotes
      ? '<button type="button" class="notes-btn">' + escapeHtml(order.additionalNotes) + "</button>"
      : "";
    const statusButtons = STATUSES.map(
      (s) =>
        '<button class="status-btn ' +
        s +
        (order.status === s ? " selected" : "") +
        '" data-status="' +
        s +
        '">' +
        STATUS_LABELS[s] +
        "</button>"
    ).join("");

    return (
      '<div class="order-card" id="card-' +
      order.id +
      '">' +
      '<div class="row-top">' +
      '<div class="row-top-text">' +
      '<button class="customer-link" type="button">' +
      escapeHtml(customerName) +
      (order.customer.company ? " · " + escapeHtml(order.customer.company) : "") +
      "</button>" +
      '<div class="meta">' +
      fmtDate(order.created) +
      (location ? " · " + escapeHtml(location) : "") +
      "</div>" +
      "</div>" +
      '<span class="status-badge ' +
      order.status +
      '">' +
      STATUS_LABELS[order.status] +
      "</span>" +
      "</div>" +
      notes +
      '<button class="products-toggle" data-open="0">' +
      order.productCount +
      " Artikel anzeigen ▾</button>" +
      '<div class="products-list" hidden></div>' +
      '<div class="status-buttons">' +
      statusButtons +
      "</div>" +
      "</div>"
    );
  }

  function formatPhoneDisplay(phone) {
    const trimmed = String(phone).trim();
    const hasPlus = trimmed.startsWith("+");
    const digits = trimmed.replace(/[^0-9]/g, "");
    if (!digits) return trimmed;

    const groups = [digits.slice(0, 1)];
    if (digits.length > 1) groups.push(digits.slice(1, 3));
    if (digits.length > 3) groups.push(digits.slice(3, 5));
    if (digits.length > 5) groups.push(...(digits.slice(5).match(/.{1,3}/g) || []));

    return (hasPlus ? "+" : "") + groups.join(" ");
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
  }

  async function toggleProducts(order, card) {
    const list = card.querySelector(".products-list");
    const toggle = card.querySelector(".products-toggle");
    const isOpen = toggle.dataset.open === "1";
    if (isOpen) {
      list.hidden = true;
      toggle.dataset.open = "0";
      toggle.textContent = order.productCount + " Artikel anzeigen ▾";
      return;
    }
    toggle.textContent = "Lade...";
    try {
      let detail = state.detailCache[order.id];
      if (!detail) {
        detail = await api("/orders/" + order.id);
        state.detailCache[order.id] = detail;
      }
      list.innerHTML = detail.products
        .map((p) => '<div class="p-row"><span>' + escapeHtml(p.title || "?") + '</span><span>' + p.quantity + "x</span></div>")
        .join("") || '<div class="p-row"><span>Keine Artikel</span></div>';
      list.hidden = false;
      toggle.dataset.open = "1";
      toggle.textContent = order.productCount + " Artikel verbergen ▴";
    } catch (e) {
      showToast(e.message, true);
      toggle.textContent = order.productCount + " Artikel anzeigen ▾";
    }
  }

  async function handleStatusChange(order, newStatus) {
    if (order.status === newStatus) return;
    const previous = order.status;
    order.status = newStatus;
    renderList();

    try {
      await api("/orders/" + order.id + "/status", { method: "POST", body: { status: newStatus } });
      showToast("Status geändert: " + STATUS_LABELS[newStatus]);
      refreshCounts();
      if (state.filter && state.filter !== newStatus) {
        // no longer belongs in this filtered view
        state.orders = state.orders.filter((o) => o.id !== order.id);
        renderList();
        els.emptyState.hidden = state.orders.length > 0;
      }
    } catch (e) {
      order.status = previous;
      renderList();
      showToast(e.message, true);
    }
  }

  function initFilters() {
    els.filters.querySelectorAll(".chip").forEach((chip) => {
      chip.addEventListener("click", () => {
        els.filters.querySelectorAll(".chip").forEach((c) => c.classList.remove("active"));
        chip.classList.add("active");
        state.filter = chip.dataset.status;
        loadOrders(true);
      });
    });
  }

  let searchDebounce;
  els.searchInput.addEventListener("input", () => {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => {
      state.search = els.searchInput.value.trim();
      loadOrders(true);
    }, 350);
  });

  els.settingsBtn.addEventListener("click", () => openSettings(true));

  els.connectBtn.addEventListener("click", async () => {
    const baseUrl = els.inputBaseUrl.value.trim() || "/api";
    const token = els.inputToken.value.trim();
    if (!token) {
      els.settingsError.textContent = "Bitte Token eingeben.";
      return;
    }
    els.settingsError.textContent = "Prüfe Verbindung...";
    const prevBase = state.baseUrl;
    const prevToken = state.token;
    state.baseUrl = baseUrl;
    state.token = token;
    try {
      await api("/orders?limit=1");
      localStorage.setItem("sola_api_base", baseUrl);
      localStorage.setItem("sola_api_token", token);
      closeSettings();
      refreshCounts();
      loadOrders(true);
    } catch (e) {
      state.baseUrl = prevBase;
      state.token = prevToken;
      els.settingsError.textContent = "Verbindung fehlgeschlagen: " + e.message;
    }
  });

  els.loadMoreBtn.addEventListener("click", () => loadOrders(false));

  initFilters();

  if (hasConfig()) {
    closeSettings();
    refreshCounts();
    loadOrders(true);
  } else {
    openSettings(false);
  }

  if ("serviceWorker" in navigator) {
    window.addEventListener("load", () => {
      navigator.serviceWorker.register("/pwa/sw.js", { scope: "/pwa/" }).catch(() => {});
    });
  }
})();
