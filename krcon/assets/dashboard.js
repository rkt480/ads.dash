(() => {
  "use strict";

  const dashboardConfig = {
    accountName: document.body.dataset.accountName || "Conta de anúncios",
    clientBrand: document.body.dataset.clientBrand || "Publiai Ads"
  };
  document.title = dashboardConfig.clientBrand + " · " + dashboardConfig.accountName;

  const state = {
    view: "overview",
    platform: "Todas",
    query: "",
    period: "30d",
    collapsed: false,
    mobileOpen: false,
    selectedCampaign: null,
    loading: false,
    error: null,
    integrations: { meta: "unknown", google: "unknown" },
    generatedAt: null,
    requestId: 0
  };
  let campaigns = [];

  const money = value => new Intl.NumberFormat("pt-BR", {
    style: "currency", currency: "BRL", minimumFractionDigits: 2, maximumFractionDigits: 2
  }).format(Number(value) || 0);
  const integer = value => new Intl.NumberFormat("pt-BR", { maximumFractionDigits: 0 }).format(Number(value) || 0);
  const decimal = value => new Intl.NumberFormat("pt-BR", { maximumFractionDigits: 2 }).format(Number(value) || 0);
  const percent = value => (Number(value) || 0).toLocaleString("pt-BR", {
    minimumFractionDigits: 2, maximumFractionDigits: 2
  }) + "%";
  const escapeHtml = value => String(value ?? "").replace(/[&<>"']/g, char => ({
    "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;"
  })[char]);

  const icons = {
    investment: '<svg viewBox="0 0 24 24" fill="none"><path d="M4 7.5h16v12H4zM4 7.5l2-3h12l2 3M15.5 13.5h2" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/><circle cx="8" cy="13.5" r="1.2" fill="currentColor"/></svg>',
    clicks: '<svg viewBox="0 0 24 24" fill="none"><path d="m5 3 13 10-6 1.2-2.7 5.3L5 3Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="m13 15 3 4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>',
    impressions: '<svg viewBox="0 0 24 24" fill="none"><path d="M2.5 12s3.4-6 9.5-6 9.5 6 9.5 6-3.4 6-9.5 6-9.5-6-9.5-6Z" stroke="currentColor" stroke-width="1.7"/><circle cx="12" cy="12" r="2.6" stroke="currentColor" stroke-width="1.7"/></svg>',
    ctr: '<svg viewBox="0 0 24 24" fill="none"><path d="M4 17 9 12l3.5 3.5L20 7.5M15 7.5h5v5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    info: '<svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.7"/><path d="M12 11v5m0-8h.01" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
    chevron: '<svg viewBox="0 0 24 24" fill="none"><path d="m9 18 6-6-6-6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>'
  };
  const icon = name => icons[name] || "";

  function adaptCampaigns(report) {
    const result = [];
    [["meta", "Meta Ads"], ["google", "Google Ads"]].forEach(([key, platform]) => {
      const rows = report[key]?.campaigns;
      if (!Array.isArray(rows)) return;
      rows.forEach((row, index) => {
        const metaResult = Array.isArray(row.results) ? row.results[0] : null;
        result.push({
          id: key + "-" + String(row.id || index),
          name: String(row.name || "Campanha sem nome"),
          platform,
          objective: String(row.objective || ""),
          status: String(row.status || ""),
          spend: Number(row.spend) || 0,
          impressions: Number(row.impressions) || 0,
          clicks: Number(row.clicks) || 0,
          ctr: Number(row.ctr) || 0,
          conversions: Number(row.conversions) || 0,
          result: metaResult && typeof metaResult === "object" ? {
            type: String(metaResult.type || ""),
            value: Number(metaResult.value) || 0
          } : null
        });
      });
    });
    return result;
  }

  function platformCampaigns(platform = state.platform) {
    return platform === "Todas" ? campaigns : campaigns.filter(row => row.platform === platform);
  }

  function totals(rows = platformCampaigns()) {
    const result = rows.reduce((sum, row) => {
      sum.spend += row.spend;
      sum.clicks += row.clicks;
      sum.impressions += row.impressions;
      sum.conversions += row.conversions;
      return sum;
    }, { spend: 0, clicks: 0, impressions: 0, conversions: 0 });
    result.ctr = result.impressions > 0 ? result.clicks / result.impressions * 100 : 0;
    return result;
  }

  function integrationStatus(platform) {
    return state.integrations[platform === "Meta Ads" ? "meta" : "google"] || "unknown";
  }

  function connectionSummary() {
    const meta = integrationStatus("Meta Ads");
    const google = integrationStatus("Google Ads");
    if (state.loading) return { label: "CONSULTANDO SERVIDOR", css: "partial", details: "Buscando dados de " + dashboardConfig.accountName + "." };
    if (state.error) return { label: "DADOS INDISPONÍVEIS", css: "unavailable", details: "A consulta ao servidor não foi concluída." };
    if (meta === "connected" && google === "connected") {
      return { label: "DADOS ATUALIZADOS", css: "connected", details: "Meta Ads e Google Ads conectados." };
    }
    if (meta === "connected" || google === "connected") {
      const missing = meta === "connected" ? "Google Ads" : "Meta Ads";
      const active = meta === "connected" ? "Meta Ads" : "Google Ads";
      return { label: "INTEGRAÇÃO PARCIAL", css: "partial", details: active + " conectado · " + missing + " sem configuração." };
    }
    if (meta === "unavailable" || google === "unavailable") {
      return { label: "VERIFICAR CONEXÃO", css: "unavailable", details: "Uma plataforma não respondeu. Confira as credenciais no servidor." };
    }
    return { label: "SEM CONEXÃO", css: "unavailable", details: "Configure as credenciais de anúncio no servidor." };
  }

  function connectionAlert() {
    if (state.error === "auth") {
      return '<div class="connection-alert error" role="alert">O servidor recusou a consulta. Verifique se o endpoint público foi atualizado junto com o dashboard.</div>';
    }
    if (state.error) {
      return '<div class="connection-alert error" role="alert">Não foi possível buscar o relatório. Confira o token, o ID da conta e o registro de erros do servidor.</div>';
    }
    if (state.loading) return "";
    const statuses = [state.integrations.meta, state.integrations.google];
    if (statuses.includes("unavailable")) {
      return '<div class="connection-alert error" role="status">Uma integração está temporariamente indisponível. Os dados da plataforma conectada continuam disponíveis.</div>';
    }
    if (statuses.includes("not_configured")) {
      const missing = [];
      if (state.integrations.meta === "not_configured") missing.push("Meta Ads");
      if (state.integrations.google === "not_configured") missing.push("Google Ads");
      return '<div class="connection-alert" role="status">' + escapeHtml(missing.join(" e ")) + ' ainda sem credenciais. O painel mostra somente as plataformas configuradas.</div>';
    }
    return "";
  }

  function pageHeading(title, description) {
    const status = connectionSummary();
    return '<div class="page-heading"><div><p class="eyebrow">' + escapeHtml(dashboardConfig.clientBrand) + '</p><h1>' + escapeHtml(title) + '</h1><p class="page-description">' + escapeHtml(description) + '</p></div><span class="demo-pill ' + status.css + '"><i class="sample-dot"></i>' + escapeHtml(status.label) + '</span></div>';
  }

  function platformFilters() {
    const choices = ["Todas", "Meta Ads", "Google Ads"];
    return '<div class="filter-row"><div class="filter-context">Plataforma <strong>' + escapeHtml(state.platform) + '</strong><span class="filter-separator"></span><span>Dados consultados diretamente das contas conectadas.</span></div>' + choices.map(platform => '<button type="button" class="filter-chip ' + (state.platform === platform ? "active" : "") + '" data-action="platform" data-platform="' + escapeHtml(platform) + '">' + (platform !== "Todas" ? '<span class="chip-dot ' + (platform === "Meta Ads" ? "meta" : "google") + '"></span>' : "") + escapeHtml(platform) + '</button>').join("") + '</div>';
  }

  function metricCard(title, value, foot, tone, iconName) {
    return '<article class="card metric-card tone-' + tone + '"><div class="metric-top"><span class="metric-title">' + escapeHtml(title) + '</span><span class="metric-icon ' + tone + '">' + icon(iconName) + '</span></div><div class="metric-value">' + escapeHtml(value) + '</div><div class="metric-foot"><span>' + escapeHtml(foot) + '</span></div></article>';
  }

  function metricCards() {
    const rows = platformCampaigns();
    const summary = totals(rows);
    const selectedConnected = state.platform === "Todas"
      ? state.integrations.meta === "connected" || state.integrations.google === "connected"
      : integrationStatus(state.platform) === "connected";
    const unavailable = !selectedConnected || state.loading || state.error;
    return [
      metricCard("Investimento", unavailable ? "—" : money(summary.spend), "no período selecionado", "blue", "investment"),
      metricCard("Cliques", unavailable ? "—" : integer(summary.clicks), "nas campanhas consultadas", "green", "clicks"),
      metricCard("Impressões", unavailable ? "—" : integer(summary.impressions), "nas campanhas consultadas", "purple", "impressions"),
      metricCard("CTR média", unavailable ? "—" : percent(summary.ctr), "cliques ÷ impressões", "amber", "ctr")
    ].join("");
  }

  function insightText() {
    const summary = totals();
    if (state.error) return "Os dados não foram carregados nesta consulta.";
    if (state.loading) return "Consultando os dados de " + dashboardConfig.accountName + " no servidor.";
    if (platformCampaigns().length === 0) return "Nenhuma campanha retornou dados para o período selecionado.";
    return "Foram investidos " + money(summary.spend) + " em " + integer(summary.clicks) + " cliques e " + integer(summary.impressions) + " impressões no período.";
  }

  function campaignSpendChart(rows) {
    const top = rows.slice().sort((a, b) => b.spend - a.spend).slice(0, 6);
    if (!top.length) return '<div class="table-empty">As campanhas aparecerão aqui quando houver dados no período.</div>';
    const max = Math.max(...top.map(row => row.spend), 0);
    return '<div class="campaign-spend-chart">' + top.map(row => {
      const width = max > 0 ? Math.max(1, row.spend / max * 100) : 0;
      return '<div class="spend-chart-row"><span class="spend-chart-name" title="' + escapeHtml(row.name) + '">' + escapeHtml(row.name) + '</span><div class="spend-chart-track"><div class="spend-chart-bar ' + (row.platform === "Google Ads" ? "google" : "") + '" style="width:' + width.toFixed(2) + '%"></div></div><strong class="spend-chart-value">' + money(row.spend) + '</strong></div>';
    }).join("") + '</div>';
  }

  function resultTypeLabel(type) {
    const raw = String(type || "").trim();
    if (!raw) return "Resultado da campanha";
    const normalized = raw.toLocaleLowerCase("pt-BR")
      .replace(/^(actions?|conversions?):/, "")
      .replace(/[.\s-]+/g, "_");
    const labels = {
      link_click: "Cliques no link",
      link_clicks: "Cliques no link",
      landing_page_view: "Visualizações da página de destino",
      landing_page_views: "Visualizações da página de destino",
      post_engagement: "Engajamentos com a publicação",
      post_engagements: "Engajamentos com a publicação",
      page_engagement: "Engajamentos com a página",
      page_engagements: "Engajamentos com a página",
      lead: "Leads",
      leads: "Leads",
      offsite_conversion_fb_pixel_lead: "Leads",
      onsite_conversion_lead_grouped: "Leads",
      onsite_conversion_lead: "Leads",
      purchase: "Compras",
      omni_purchase: "Compras",
      offsite_conversion_fb_pixel_purchase: "Compras no site",
      onsite_conversion_purchase: "Compras",
      onsite_conversion_messaging_conversation_started_7d: "Conversas por mensagem iniciadas",
      messaging_conversation_started_7d: "Conversas por mensagem iniciadas",
      messaging_conversations_started: "Conversas por mensagem iniciadas",
      onsite_conversion_total_messaging_connection: "Conexões por mensagem",
      page_like: "Curtidas na página",
      post_reaction: "Reações à publicação",
      post_save: "Salvamentos da publicação",
      video_view: "Visualizações de vídeo",
      video_views: "Visualizações de vídeo",
      thruplay: "ThruPlays",
      complete_registration: "Cadastros concluídos",
      onsite_conversion_complete_registration: "Cadastros concluídos",
      add_to_cart: "Adições ao carrinho",
      initiate_checkout: "Finalizações de compra iniciadas",
      contact: "Contatos",
      schedule: "Agendamentos",
      subscribe: "Assinaturas",
      content_view: "Visualizações de conteúdo",
      search: "Pesquisas"
    };
    if (labels[normalized]) return labels[normalized];
    if (/[^a-z0-9_.:-]/i.test(raw)) return raw;
    return raw.replace(/^(actions?|conversions?):/i, "").replace(/[._-]+/g, " ");
  }

  function campaignOutcome(row) {
    if (row.platform === "Google Ads") {
      return { value: row.conversions, label: "Conversões" };
    }
    return {
      value: row.result ? row.result.value : 0,
      label: resultTypeLabel(row.result?.type)
    };
  }

  function resultColumnTitle() {
    if (state.platform === "Google Ads") return "Conversões";
    if (state.platform === "Meta Ads") return "Resultados";
    return "Resultados / conversões";
  }

  function campaignResultCell(row, showPlatform) {
    const outcome = campaignOutcome(row);
    const showLabel = showPlatform || state.platform === "Todas" || row.platform === "Meta Ads";
    return '<td class="result-cell"><strong>' + decimal(outcome.value) + '</strong>'
      + (showLabel ? '<span class="result-caption" title="' + escapeHtml(outcome.label) + '">' + escapeHtml(outcome.label) + '</span>' : '')
      + '</td>';
  }

  function spendDistribution() {
    const platforms = state.platform === "Todas" ? ["Meta Ads", "Google Ads"] : [state.platform];
    const rows = platforms.map(platform => {
      const status = integrationStatus(platform);
      const spend = campaigns.filter(row => row.platform === platform).reduce((sum, row) => sum + row.spend, 0);
      return { platform, status, spend };
    });
    const connectedSpend = rows.filter(row => row.status === "connected").reduce((sum, row) => sum + row.spend, 0);
    const bars = rows.filter(row => row.status === "connected").map(row => {
      const width = connectedSpend > 0 ? row.spend / connectedSpend * 100 : 0;
      return '<span class="' + (row.platform === "Meta Ads" ? "stacked-meta" : "stacked-google") + '" style="width:' + width.toFixed(2) + '%"></span>';
    }).join("");
    const legend = rows.map(row => {
      const connected = row.status === "connected";
      const share = connected && connectedSpend > 0 ? row.spend / connectedSpend * 100 : 0;
      return '<div class="spend-legend-row"><i class="legend-dot ' + (row.platform === "Google Ads" ? "google" : "") + '"></i><div>' + escapeHtml(row.platform) + '<small>' + (connected ? money(row.spend) + ' · ' + percent(share) : 'Sem conexão') + '</small></div><strong>' + (connected ? percent(share) : '—') + '</strong></div>';
    }).join("");
    const allMissing = rows.every(row => row.status !== "connected");
    const selectedSpend = state.platform === "Todas" ? connectedSpend : rows[0].spend;
    return '<div class="spend-total">' + (allMissing || state.error || state.loading ? '—' : money(selectedSpend)) + '<small>investidos</small></div><div class="stacked-bar" aria-label="Distribuição do investimento por plataforma">' + bars + '</div><div class="spend-legend">' + legend + '</div><p class="spend-note">Percentuais calculados somente entre as plataformas conectadas.</p>';
  }

  function campaignTable(rows, showPlatform) {
    if (!rows.length) return '<div class="table-empty">Nenhuma campanha retornada para estes filtros.</div>';
    return '<div class="table-scroll"><table><thead><tr><th>Campanha</th>'
      + (showPlatform ? '<th>Plataforma</th>' : '')
      + '<th>Investimento</th><th>Cliques</th><th>' + escapeHtml(resultColumnTitle()) + '</th><th>Impressões</th><th>CTR</th></tr></thead><tbody>'
      + rows.map(row => '<tr class="campaign-row" tabindex="0" role="button" aria-label="Ver detalhes: ' + escapeHtml(row.name) + '" data-action="campaign" data-id="' + escapeHtml(row.id) + '"><td><div class="campaign-name">' + escapeHtml(row.name) + '</div>' + (row.objective ? '<div class="campaign-objective">' + escapeHtml(row.objective) + '</div>' : '') + '</td>'
        + (showPlatform ? '<td><span class="platform-badge ' + (row.platform === "Google Ads" ? "google" : "") + '"><i class="mini-dot"></i>' + escapeHtml(row.platform) + '</span></td>' : '')
        + '<td class="td-strong">' + money(row.spend) + '</td><td>' + integer(row.clicks) + '</td>' + campaignResultCell(row, showPlatform)
        + '<td>' + integer(row.impressions) + '</td><td>' + percent(row.ctr) + '</td></tr>').join("")
      + '</tbody></table></div>';
  }

  function filteredCampaigns() {
    const query = state.query.trim().toLocaleLowerCase("pt-BR");
    return platformCampaigns().filter(row => !query || (row.name + " " + row.objective + " " + row.platform).toLocaleLowerCase("pt-BR").includes(query));
  }

  function overviewPage() {
    const rows = platformCampaigns();
    const top = rows.slice().sort((a, b) => b.spend - a.spend).slice(0, 5);
    const summary = totals();
    return pageHeading("Visão geral", "Indicadores e campanhas da conta de anúncios " + dashboardConfig.accountName + ".") + connectionAlert() + platformFilters() + '<section class="metric-grid" aria-label="Indicadores do período">' + metricCards() + '</section><section class="insight-banner"><div class="insight-copy"><strong>Resumo do período</strong><p>' + escapeHtml(insightText()) + '</p></div></section><section class="overview-grid"><article class="card chart-card"><div class="card-heading"><div><h2 class="card-title">Investimento por campanha</h2><p class="card-subtitle">Maiores investimentos no período · dados da API</p></div></div>' + campaignSpendChart(rows) + '</article><article class="card spend-card"><div class="card-heading"><div><h2 class="card-title">Investimento por plataforma</h2><p class="card-subtitle">Distribuição entre contas conectadas</p></div></div>' + spendDistribution() + '</article></section><section class="card table-card"><div class="card-heading"><div><h2 class="card-title">Campanhas em destaque</h2><p class="card-subtitle">Ordenadas pelo investimento no período</p></div><button class="text-button" type="button" data-action="navigate" data-view="campaigns">Ver todas ' + icon("chevron") + '</button></div>' + campaignTable(top, false) + '</section><div class="footer-note">' + icon("info") + ' Dados consultados no servidor. Atualizado em ' + escapeHtml(formatGeneratedAt()) + '.</div>';
  }

  function campaignsPage() {
    const filtered = filteredCampaigns().slice().sort((a, b) => b.spend - a.spend);
    return pageHeading(state.platform === "Todas" ? "Campanhas" : "Campanhas · " + state.platform, "Pesquise as campanhas e compare investimento, cliques, resultados e impressões.") + connectionAlert() + platformFilters() + '<div class="campaign-toolbar"><label class="search-field"><svg viewBox="0 0 24 24" fill="none"><circle cx="10.8" cy="10.8" r="6.8" stroke="currentColor" stroke-width="1.7"/><path d="m16 16 4.5 4.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg><input id="campaignSearch" type="search" placeholder="Buscar campanha..." value="' + escapeHtml(state.query) + '" aria-label="Buscar campanha"></label><span class="filter-chip active" aria-live="polite">' + filtered.length + ' campanhas</span></div><section class="card table-card"><div class="card-heading"><div><h2 class="card-title">Campanhas da conta</h2><p class="card-subtitle">Clique em uma linha para abrir os indicadores</p></div></div>' + campaignTable(filtered, state.platform === "Todas") + '<div class="table-meta"><span>Mostrando ' + filtered.length + ' de ' + campaigns.length + ' campanhas carregadas</span><span class="table-hint">' + icon("info") + ' Métricas do período selecionado</span></div></section>';
  }

  function comparePage() {
    return pageHeading("Comparativo", "Esta área está oculta enquanto a comparação não estiver conectada aos dados reais.") + '<section class="card table-card"><div class="table-empty">Comparativo temporariamente indisponível.</div></section>';
  }

  function formatGeneratedAt() {
    if (!state.generatedAt) return "aguardando primeira consulta";
    const date = new Date(state.generatedAt);
    if (Number.isNaN(date.getTime())) return "consulta concluída";
    return new Intl.DateTimeFormat("pt-BR", { dateStyle: "short", timeStyle: "short" }).format(date);
  }

  function updateSidebarConnection() {
    const status = connectionSummary();
    const title = document.getElementById("connectionTitle");
    const details = document.getElementById("connectionDetails");
    if (title) title.textContent = state.error ? "Falha na consulta" : state.loading ? "Conectando ao servidor" : "Consulta somente leitura";
    if (details) details.textContent = status.details;
    const updatedAt = document.getElementById("updatedAt");
    if (updatedAt) updatedAt.textContent = formatGeneratedAt();
  }

  function render() {
    const content = document.getElementById("pageContent");
    if (!content) return;
    content.innerHTML = state.view === "campaigns" ? campaignsPage() : state.view === "compare" ? comparePage() : overviewPage();
    document.getElementById("breadcrumbPage").textContent = state.view === "campaigns" ? "Campanhas" : state.view === "compare" ? "Comparativo" : "Visão geral";
    document.getElementById("periodSelect").value = state.period;
    const sidebar = document.getElementById("sidebar");
    sidebar.classList.toggle("collapsed", state.collapsed);
    sidebar.classList.toggle("mobile-open", state.mobileOpen);
    document.getElementById("sidebarOverlay").classList.toggle("visible", state.mobileOpen);
    const collapseButton = document.querySelector(".collapse-sidebar");
    collapseButton.setAttribute("aria-label", state.collapsed ? "Expandir menu" : "Recolher menu");
    collapseButton.setAttribute("title", state.collapsed ? "Expandir menu" : "Recolher menu");
    collapseButton.setAttribute("aria-expanded", String(!state.collapsed));
    document.querySelectorAll("[data-nav]").forEach(item => item.classList.remove("active"));
    const nav = document.querySelector('[data-nav="' + (state.view === "campaigns" ? "campaigns" : state.view === "compare" ? "compare" : "overview") + '"]');
    if (nav) nav.classList.add("active");
    const platformNav = state.platform === "Todas" ? "platform-all" : state.platform === "Meta Ads" ? "platform-meta" : "platform-google";
    const activePlatform = document.querySelector('[data-nav="' + platformNav + '"]');
    if (activePlatform) activePlatform.classList.add("active");
    const accountName = document.getElementById("accountName");
    if (accountName) accountName.textContent = dashboardConfig.accountName;
    const accountSwitch = document.getElementById("accountSwitch");
    if (accountSwitch) accountSwitch.setAttribute("aria-label", "Conta selecionada: " + dashboardConfig.accountName);
    updateSidebarConnection();
  }

  function showCampaign(id) {
    const campaign = campaigns.find(row => row.id === id);
    if (!campaign) return;
    document.getElementById("modalRoot").innerHTML = '<div class="modal-backdrop" data-action="close-modal"><section class="campaign-modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle" data-modal-content><div class="modal-header"><div><h2 class="modal-title" id="modalTitle">' + escapeHtml(campaign.name) + '</h2><p class="modal-subtitle"><span class="platform-badge ' + (campaign.platform === "Google Ads" ? "google" : "") + '"><i class="mini-dot"></i>' + escapeHtml(campaign.platform) + '</span>' + (campaign.objective ? ' &nbsp; · &nbsp; ' + escapeHtml(campaign.objective) : '') + '</p></div><button class="icon-button modal-close" type="button" aria-label="Fechar detalhes" data-action="close-modal-button">✕</button></div><div class="modal-body"><div class="modal-kpis"><div class="modal-kpi"><span>Investimento</span><strong>' + money(campaign.spend) + '</strong></div><div class="modal-kpi"><span>Cliques</span><strong>' + integer(campaign.clicks) + '</strong></div><div class="modal-kpi"><span>Impressões</span><strong>' + integer(campaign.impressions) + '</strong></div><div class="modal-kpi"><span>CTR</span><strong>' + percent(campaign.ctr) + '</strong></div>' + (campaign.platform === "Google Ads" ? '<div class="modal-kpi"><span>Conversões</span><strong>' + integer(campaign.conversions) + '</strong></div>' : '') + '</div><p class="modal-explainer">Indicadores consultados na API da plataforma para o período selecionado.</p></div></section></div>';
    state.selectedCampaign = id;
    const closeButton = document.querySelector(".modal-close");
    if (closeButton) closeButton.focus();
  }

  function closeModal() {
    document.getElementById("modalRoot").innerHTML = "";
    state.selectedCampaign = null;
  }

  function showToast(message) {
    let toast = document.getElementById("toast");
    if (toast) toast.remove();
    toast = document.createElement("div");
    toast.id = "toast";
    toast.className = "toast";
    toast.setAttribute("role", "status");
    toast.textContent = message;
    document.body.appendChild(toast);
    window.setTimeout(() => toast.remove(), 2300);
  }

  async function loadReport() {
    const requestId = ++state.requestId;
    state.loading = true;
    state.error = null;
    campaigns = [];
    render();
    try {
      const endpoint = new URL("api/v1/report.php", document.baseURI);
      endpoint.searchParams.set("period", state.period);
      const response = await fetch(endpoint.toString(), {
        method: "GET",
        headers: { "Accept": "application/json" },
        credentials: "same-origin",
        cache: "no-store"
      });
      const body = await response.json().catch(() => ({}));
      if (requestId !== state.requestId) return;
      if (response.status === 401) throw new Error("auth");
      if (!response.ok || !body.data) throw new Error("service");
      state.integrations = body.data.integrations || { meta: "unknown", google: "unknown" };
      state.generatedAt = body.data.generated_at || null;
      campaigns = adaptCampaigns(body.data);
    } catch (error) {
      if (requestId !== state.requestId) return;
      state.error = error instanceof Error && error.message === "auth" ? "auth" : "service";
      campaigns = [];
    } finally {
      if (requestId === state.requestId) {
        state.loading = false;
        render();
      }
    }
  }

  document.addEventListener("click", event => {
    const actionEl = event.target.closest("[data-action]");
    if (!actionEl) return;
    const action = actionEl.dataset.action;
    if (action === "navigate") {
      state.view = actionEl.dataset.view;
      state.mobileOpen = false;
      render();
    }
    if (action === "platform") {
      state.platform = actionEl.dataset.platform;
      state.view = "overview";
      state.mobileOpen = false;
      render();
    }
    if (action === "campaign") showCampaign(actionEl.dataset.id);
    if (action === "close-modal" && !event.target.closest("[data-modal-content]")) closeModal();
    if (action === "close-modal-button") closeModal();
    if (action === "collapse-sidebar") { state.collapsed = !state.collapsed; render(); }
    if (action === "toggle-mobile") { state.mobileOpen = !state.mobileOpen; render(); }
    if (action === "close-mobile") { state.mobileOpen = false; render(); }
    if (action === "refresh") {
      actionEl.classList.add("is-refreshing");
      loadReport().finally(() => actionEl.classList.remove("is-refreshing"));
    }
  });

  document.addEventListener("input", event => {
    if (event.target.id !== "campaignSearch") return;
    state.query = event.target.value;
    const cursor = event.target.selectionStart;
    render();
    const input = document.getElementById("campaignSearch");
    if (input) { input.focus(); input.setSelectionRange(cursor, cursor); }
  });

  document.addEventListener("change", event => {
    if (event.target.id === "periodSelect") {
      state.period = event.target.value;
      loadReport();
    }
  });

  document.addEventListener("keydown", event => {
    if (event.key === "Escape") {
      if (state.selectedCampaign) closeModal();
      if (state.mobileOpen) { state.mobileOpen = false; render(); }
    }
    if ((event.key === "Enter" || event.key === " ") && event.target.matches("tr.campaign-row")) {
      event.preventDefault();
      showCampaign(event.target.dataset.id);
    }
  });

  render();
  loadReport();
})();
