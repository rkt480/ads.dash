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
    customSince: "",
    customUntil: "",
    customApplied: false,
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
  let metaAds = [];
  let metaAdsStatus = "unknown";

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
  const hasValidCustomRange = () => /^\d{4}-\d{2}-\d{2}$/.test(state.customSince)
    && /^\d{4}-\d{2}-\d{2}$/.test(state.customUntil)
    && state.customSince <= state.customUntil;

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

  function adaptMetaAds(metaReport) {
    const rows = metaReport?.ads;
    if (!Array.isArray(rows)) return [];
    return rows.map(row => ({
      id: String(row.id || ""),
      name: String(row.name || "Anúncio sem nome"),
      campaignName: String(row.campaign_name || "Campanha sem nome"),
      platform: "Meta Ads",
      previewUrl: String(row.preview_url || ""),
      mediaType: String(row.media_type || ""),
      result: row.result && typeof row.result === "object" ? {
        type: String(row.result.type || ""),
        value: Number(row.result.value) || 0
      } : { type: "", value: 0 },
      reach: Number(row.reach) || 0,
      impressions: Number(row.impressions) || 0,
      ctr: Number(row.ctr) || 0,
      clicks: Number(row.clicks) || 0,
      spend: Number(row.spend) || 0
    }));
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
    if (meta === "unavailable" || google === "unavailable") {
      const unavailable = meta === "unavailable" ? "Meta Ads" : "Google Ads";
      return { label: "VERIFICAR CONEXÃO", css: "unavailable", details: unavailable + " está temporariamente indisponível." };
    }
    if (meta === "connected" && google === "connected") {
      return { label: "DADOS ATUALIZADOS", css: "connected", details: "Meta Ads e Google Ads conectados." };
    }
    if (meta === "connected" || google === "connected") {
      const missing = meta === "connected" ? "Google Ads" : "Meta Ads";
      const active = meta === "connected" ? "Meta Ads" : "Google Ads";
      return { label: "INTEGRAÇÃO PARCIAL", css: "partial", details: active + " conectado · " + missing + " sem configuração." };
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
      .replace(/[^a-z0-9]+/g, "_")
      .replace(/^_+|_+$/g, "");
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
      on_facebook_lead: "Leads por formulário instantâneo",
      on_facebook_leads: "Leads por formulário instantâneo",
      leads_on_facebook: "Leads por formulário instantâneo",
      leads_no_facebook: "Leads por formulário instantâneo",
      facebook_leads: "Leads por formulário instantâneo",
      lead_form: "Leads por formulário instantâneo",
      lead_forms: "Leads por formulário instantâneo",
      instant_form_leads: "Leads por formulário instantâneo",
      lead_form_submissions: "Leads por formulário instantâneo",
      onsite_conversion_lead: "Leads por formulário instantâneo",
      onsite_conversion_leads: "Leads por formulário instantâneo",
      offsite_conversion_fb_pixel_lead: "Leads",
      onsite_conversion_lead_grouped: "Leads por formulário instantâneo",
      onsite_conversion_leadgen_grouped: "Leads por formulário instantâneo",
      website_lead: "Leads no site",
      website_leads: "Leads no site",
      purchase: "Compras",
      purchases: "Compras",
      omni_purchase: "Compras",
      offsite_conversion_fb_pixel_purchase: "Compras no site",
      website_purchases: "Compras no site",
      onsite_conversion_purchase: "Compras",
      onsite_conversion_messaging_conversation_started_7d: "Conversas por mensagem iniciadas",
      messaging_conversation_started_7d: "Conversas por mensagem iniciadas",
      messaging_conversations_started: "Conversas por mensagem iniciadas",
      onsite_conversion_total_messaging_connection: "Conexões por mensagem",
      page_like: "Curtidas na página",
      page_likes: "Curtidas na página",
      post_reaction: "Reações à publicação",
      post_reactions: "Reações à publicação",
      post_save: "Salvamentos da publicação",
      post_saves: "Salvamentos da publicação",
      video_view: "Visualizações de vídeo",
      video_views: "Visualizações de vídeo",
      thruplay: "ThruPlays",
      reach: "Alcance",
      impressions: "Impressões",
      complete_registration: "Cadastros concluídos",
      completed_registrations: "Cadastros concluídos",
      onsite_conversion_complete_registration: "Cadastros concluídos",
      add_to_cart: "Adições ao carrinho",
      initiate_checkout: "Finalizações de compra iniciadas",
      app_install: "Instalações do aplicativo",
      app_installs: "Instalações do aplicativo",
      mobile_app_install: "Instalações do aplicativo",
      contact: "Contatos",
      contacts: "Contatos",
      schedule: "Agendamentos",
      appointments_scheduled: "Agendamentos",
      subscribe: "Assinaturas",
      content_view: "Visualizações de conteúdo",
      search: "Pesquisas",
      event_response: "Respostas ao evento",
      event_responses: "Respostas ao evento",
      profile_visit: "Visitas ao perfil",
      profile_visits: "Visitas ao perfil"
    };
    if (labels[normalized]) return labels[normalized];
    if (normalized.includes("lead")) {
      if (normalized.includes("website") || normalized.includes("offsite") || normalized.includes("pixel")) return "Leads no site";
      if (normalized.includes("onsite") || normalized.includes("form") || normalized.includes("facebook")) return "Leads por formulário instantâneo";
      return "Leads";
    }
    if (normalized.includes("purchase")) return normalized.includes("website") || normalized.includes("offsite") ? "Compras no site" : "Compras";
    if (normalized.includes("message") || normalized.includes("conversation")) return "Conversas por mensagem iniciadas";
    if (normalized.includes("landing_page") && normalized.includes("view")) return "Visualizações da página de destino";
    if (normalized.includes("link") && normalized.includes("click")) return "Cliques no link";
    if (normalized.includes("engagement")) return "Engajamentos com a publicação";
    if (normalized.includes("video") || normalized.includes("thruplay")) return "Visualizações de vídeo";
    if (normalized.includes("profile") && normalized.includes("visit")) return "Visitas ao perfil";
    if (normalized.includes("install")) return "Instalações do aplicativo";
    if (normalized.includes("reach")) return "Alcance";
    if (normalized.includes("impression")) return "Impressões";
    if (/[áàâãéêíóôõúç]/i.test(raw)) return raw;
    return "Resultado da campanha";
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

  function campaignStatus(row) {
    const status = String(row.status || "").toUpperCase();
    if (status === "ACTIVE" || status === "ENABLED") return { label: "Ativa", className: "active" };
    if (status.includes("PAUSED") || status === "DISABLED") return { label: "Pausada", className: "paused" };
    if (status === "ARCHIVED") return { label: "Arquivada", className: "inactive" };
    if (status === "DELETED" || status === "REMOVED") return { label: "Removida", className: "inactive" };
    if (status === "WITH_ISSUES") return { label: "Com problemas", className: "paused" };
    if (status === "IN_PROCESS") return { label: "Em processamento", className: "unknown" };
    return { label: "Não informado", className: "unknown" };
  }

  function campaignStatusCell(row) {
    const status = campaignStatus(row);
    return '<td><span class="status-badge ' + status.className + '">' + escapeHtml(status.label) + '</span></td>';
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
      + '<th>Status</th><th>Investimento</th><th>Cliques</th><th>' + escapeHtml(resultColumnTitle()) + '</th><th>Impressões</th><th>CTR</th></tr></thead><tbody>'
      + rows.map(row => '<tr class="campaign-row" tabindex="0" role="button" aria-label="Ver detalhes: ' + escapeHtml(row.name) + '" data-action="campaign" data-id="' + escapeHtml(row.id) + '"><td><div class="campaign-name">' + escapeHtml(row.name) + '</div>' + (row.objective ? '<div class="campaign-objective">' + escapeHtml(row.objective) + '</div>' : '') + '</td>'
        + (showPlatform ? '<td><span class="platform-badge ' + (row.platform === "Google Ads" ? "google" : "") + '"><i class="mini-dot"></i>' + escapeHtml(row.platform) + '</span></td>' : '')
        + campaignStatusCell(row) + '<td class="td-strong">' + money(row.spend) + '</td><td>' + integer(row.clicks) + '</td>' + campaignResultCell(row, showPlatform)
        + '<td>' + integer(row.impressions) + '</td><td>' + percent(row.ctr) + '</td></tr>').join("")
      + '</tbody></table></div>';
  }

  function metaAdPreviewCell(ad) {
    const alt = "Prévia do anúncio: " + ad.name;
    const fallback = '<span class="ad-preview-fallback">Prévia indisponível</span>';
    const media = ad.previewUrl
      ? '<img class="ad-preview-image" src="' + escapeHtml(ad.previewUrl) + '" alt="' + escapeHtml(alt) + '" loading="lazy"><span class="ad-preview-fallback" hidden>Prévia indisponível</span>'
      : fallback;
    const videoBadge = ad.mediaType === "video" ? '<span class="ad-video-badge">Vídeo</span>' : '';
    return '<td class="ad-preview-cell"><div class="ad-preview-details"><div class="ad-preview-frame">' + media + videoBadge + '</div>'
      + '<div class="ad-preview-name" title="' + escapeHtml(ad.name) + '">' + escapeHtml(ad.name) + '</div></div></td>';
  }

  function metaAdsTable() {
    let emptyMessage = "Nenhum anúncio retornou dados para o período selecionado.";
    if (state.loading) emptyMessage = "Consultando anúncios da Meta...";
    else if (integrationStatus("Meta Ads") === "not_configured") emptyMessage = "Conecte o Meta Ads para consultar os anúncios.";
    else if (integrationStatus("Meta Ads") === "unavailable") emptyMessage = "Não foi possível consultar a conta do Meta Ads neste momento.";
    else if (metaAdsStatus === "unavailable") emptyMessage = "Não foi possível consultar as métricas dos anúncios neste momento.";

    const table = metaAds.length
      ? '<div class="table-scroll"><table class="meta-ads-table"><thead><tr><th>Campanha</th><th>Anúncio</th><th>Resultados</th><th>Alcance</th><th>Impressões</th><th>CTR</th><th>Cliques</th></tr></thead><tbody>'
        + metaAds.map(ad => '<tr><td><div class="campaign-name" title="' + escapeHtml(ad.campaignName) + '">' + escapeHtml(ad.campaignName) + '</div></td>'
          + metaAdPreviewCell(ad) + campaignResultCell(ad, false)
          + '<td>' + integer(ad.reach) + '</td><td>' + integer(ad.impressions) + '</td><td>' + percent(ad.ctr) + '</td><td>' + integer(ad.clicks) + '</td></tr>').join("")
        + '</tbody></table></div>'
      : '<div class="table-empty">' + escapeHtml(emptyMessage) + '</div>';

    return '<section class="card table-card meta-ads-card"><div class="card-heading"><div><h2 class="card-title">Anúncios</h2><p class="card-subtitle">Prévia e métricas por anúncio no período selecionado</p></div></div>' + table + '</section>';
  }

  function filteredCampaigns() {
    const query = state.query.trim().toLocaleLowerCase("pt-BR");
    return platformCampaigns().filter(row => !query || (row.name + " " + row.objective + " " + row.platform).toLocaleLowerCase("pt-BR").includes(query));
  }

  function overviewPage() {
    const rows = platformCampaigns();
    const top = rows.slice().sort((a, b) => b.spend - a.spend).slice(0, 5);
    const summary = totals();
    return pageHeading("Visão geral", "Indicadores e campanhas da conta de anúncios " + dashboardConfig.accountName + ".") + connectionAlert() + platformFilters() + '<section class="metric-grid" aria-label="Indicadores do período">' + metricCards() + '</section><section class="insight-banner"><div class="insight-copy"><strong>Resumo do período</strong><p>' + escapeHtml(insightText()) + '</p></div></section><section class="overview-grid"><article class="card chart-card"><div class="card-heading"><div><h2 class="card-title">Investimento por campanha</h2><p class="card-subtitle">Maiores investimentos no período · dados da API</p></div></div>' + campaignSpendChart(rows) + '</article><article class="card spend-card"><div class="card-heading"><div><h2 class="card-title">Investimento por plataforma</h2><p class="card-subtitle">Distribuição entre contas conectadas</p></div></div>' + spendDistribution() + '</article></section><section class="card table-card"><div class="card-heading"><div><h2 class="card-title">Campanhas em destaque</h2><p class="card-subtitle">Ordenadas pelo investimento no período</p></div><button class="text-button" type="button" data-action="navigate" data-view="campaigns">Ver todas ' + icon("chevron") + '</button></div>' + campaignTable(top, false) + '</section>' + (state.platform === "Meta Ads" ? metaAdsTable() : '') + '<div class="footer-note">' + icon("info") + ' Dados consultados no servidor. Atualizado em ' + escapeHtml(formatGeneratedAt()) + '.</div>';
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
    const customPeriodNotice = state.period === "custom" && !state.customApplied
      ? '<div class="custom-period-notice">Selecione as datas e clique em Aplicar. Os dados exibidos ainda correspondem ao período anterior.</div>'
      : "";
    content.innerHTML = customPeriodNotice + (state.view === "campaigns" ? campaignsPage() : state.view === "compare" ? comparePage() : overviewPage());
    document.getElementById("breadcrumbPage").textContent = state.view === "campaigns" ? "Campanhas" : state.view === "compare" ? "Comparativo" : "Visão geral";
    const periodSelect = document.getElementById("periodSelect");
    if (periodSelect) periodSelect.value = state.period;
    const customControls = document.getElementById("customPeriodControls");
    const periodSince = document.getElementById("periodSince");
    const periodUntil = document.getElementById("periodUntil");
    const applyCustomPeriod = document.getElementById("applyCustomPeriod");
    if (customControls) customControls.hidden = state.period !== "custom";
    if (periodSince) periodSince.value = state.customSince;
    if (periodUntil) periodUntil.value = state.customUntil;
    if (applyCustomPeriod) applyCustomPeriod.disabled = !hasValidCustomRange() || state.loading;
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
    metaAds = [];
    metaAdsStatus = "unknown";
    render();
    try {
      const endpoint = new URL("api/v1/report.php", document.baseURI);
      endpoint.searchParams.set("period", state.period);
      if (state.period === "custom") {
        endpoint.searchParams.set("since", state.customSince);
        endpoint.searchParams.set("until", state.customUntil);
      }
      if (state.platform === "Meta Ads") endpoint.searchParams.set("include_meta_ads", "1");
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
      metaAds = adaptMetaAds(body.data.meta || {});
      metaAdsStatus = String(body.data.meta?.ads_status || "unknown");
    } catch (error) {
      if (requestId !== state.requestId) return;
      state.error = error instanceof Error && error.message === "auth" ? "auth" : "service";
      campaigns = [];
      metaAds = [];
      metaAdsStatus = "unavailable";
    } finally {
      if (requestId === state.requestId) {
        state.loading = false;
        render();
      }
    }
  }

  document.addEventListener("error", event => {
    const image = event.target;
    if (!(image instanceof HTMLImageElement) || !image.classList.contains("ad-preview-image")) return;
    image.hidden = true;
    const fallback = image.parentElement?.querySelector(".ad-preview-fallback");
    if (fallback) fallback.hidden = false;
  }, true);

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
      if (state.platform === "Meta Ads") loadReport();
    }
    if (action === "campaign") showCampaign(actionEl.dataset.id);
    if (action === "close-modal" && !event.target.closest("[data-modal-content]")) closeModal();
    if (action === "close-modal-button") closeModal();
    if (action === "collapse-sidebar") { state.collapsed = !state.collapsed; render(); }
    if (action === "toggle-mobile") { state.mobileOpen = !state.mobileOpen; render(); }
    if (action === "close-mobile") { state.mobileOpen = false; render(); }
    if (action === "refresh") {
      if (state.period === "custom" && (!state.customApplied || !hasValidCustomRange())) {
        showToast("Selecione as datas e clique em Aplicar.");
        return;
      }
      actionEl.classList.add("is-refreshing");
      loadReport().finally(() => actionEl.classList.remove("is-refreshing"));
    }
    if (action === "apply-custom-period") {
      if (!hasValidCustomRange()) return;
      state.customApplied = true;
      loadReport();
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
      if (state.period === "custom") {
        state.customApplied = false;
        render();
        return;
      }
      loadReport();
    }
    if (event.target.id === "periodSince" || event.target.id === "periodUntil") {
      state.customSince = document.getElementById("periodSince").value;
      state.customUntil = document.getElementById("periodUntil").value;
      state.customApplied = false;
      render();
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
