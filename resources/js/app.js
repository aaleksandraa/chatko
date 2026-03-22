import './bootstrap';

const SESSION_KEY = 'chatko_admin_session_v1';
const ONBOARDING_MAX_STEP = 3;

const state = {
    token: null,
    tenantSlug: null,
    role: null,
    user: null,
    integrations: [],
    presets: [],
    products: [],
    productsPage: 1,
    productsPerPage: 25,
    productsLastPage: 1,
    productsTotal: 0,
    productsFrom: 0,
    productsTo: 0,
    knowledgeDocuments: [],
    conversations: [],
    importJobs: [],
    tenants: [],
    auditLogs: [],
    widgetAbuseLogs: [],
    orderStatusEvents: [],
    users: [],
    widgets: [],
    activePresetIntegrationId: null,
    activeKnowledgeGuideType: 'faq',
    onboardingStep: 1,
    modalSubmitHandler: null,
    confirmHandler: null,
    widget: {
        publicKey: '',
        conversationId: null,
        sessionId: null,
        sessionToken: null,
        visitorUuid: null,
    },
};

const KNOWLEDGE_TYPE_DEFINITIONS = {
    faq: {
        label: 'FAQ',
        help: 'Brzi odgovori na cesto postavljena pitanja kupaca.',
    },
    shipping_policy: {
        label: 'Dostava',
        help: 'Rokovi, cijene i pravila dostave po gradovima/regijama.',
    },
    returns_policy: {
        label: 'Povrat i reklamacije',
        help: 'Uslovi povrata, zamjene i reklamacije.',
    },
    promotions: {
        label: 'Akcije i popusti',
        help: 'Aktivne akcije, kuponi i pravila kombinovanja popusta.',
    },
    payment_policy: {
        label: 'Placanje',
        help: 'Nacini placanja (online, pouzece, virman) i eventualna ogranicenja.',
    },
    sales_playbook: {
        label: 'Prodajni playbook',
        help: 'Pravila kako asistent vodi razgovor i preporucuje proizvode.',
    },
    product_education: {
        label: 'Edukacija proizvoda',
        help: 'Kako odabrati odgovarajuci proizvod, tipicne situacije i savjeti.',
    },
    company_info: {
        label: 'Informacije o firmi',
        help: 'Opste informacije, kontakt i radno vrijeme.',
    },
};

const KNOWLEDGE_TEMPLATE_PRESETS = {
    shipping_policy: {
        type: 'shipping_policy',
        title: 'Dostava i rokovi isporuke',
        content: [
            'PODRUCJE DOSTAVE',
            '- Dostava je dostupna na: [navedi gradove/drzave].',
            '- Ako adresa nije pokrivena, ponuditi alternativu ili preuzimanje.',
            '',
            'ROK ISPORUKE',
            '- Standardni rok: [npr. 1-3 radna dana].',
            '- Vikendom i praznicima se ne vrsi isporuka.',
            '',
            'CIJENA DOSTAVE',
            '- Cijena dostave: [npr. 7 KM].',
            '- Besplatna dostava za narudzbe iznad: [npr. 80 KM].',
            '',
            'NAPOMENE',
            '- Asistent mora jasno reci rok i cijenu prije potvrde narudzbe.',
        ].join('\n'),
    },
    returns_policy: {
        type: 'returns_policy',
        title: 'Povrat i reklamacije',
        content: [
            'ROK ZA POVRAT',
            '- Kupac moze vratiti proizvod u roku od [npr. 14 dana] od prijema.',
            '',
            'USLOVI POVRATA',
            '- Proizvod mora biti nekoristen i u originalnom pakovanju.',
            '- Potreban je dokaz o kupovini (broj narudzbe/racun).',
            '',
            'REKLAMACIJE',
            '- U slucaju ostecenja ili pogresne isporuke, kupac treba poslati fotografije i opis problema.',
            '',
            'REFUND',
            '- Refund se vraca na isti nacin placanja ili po dogovoru, u roku od [npr. 7 dana].',
        ].join('\n'),
    },
    promotions: {
        type: 'promotions',
        title: 'Akcije, popusti i kuponi',
        content: [
            'AKTIVNE AKCIJE',
            '- [Naziv akcije]: [opis], traje do [datum].',
            '',
            'KUPONI',
            '- Kupon kod: [kod], popust: [npr. 10%], uslov: [minimalna korpa].',
            '',
            'PRAVILA KOMBINOVANJA',
            '- Kuponi se [mogu/ne mogu] kombinovati sa vec snizenim artiklima.',
            '- Asistent uvijek treba reci da li je popust vec uracunat.',
            '',
            'ZALIHE AKCIJSKIH ARTIKALA',
            '- Akcija vrijedi do isteka zaliha ako nije drugacije navedeno.',
        ].join('\n'),
    },
    payment_policy: {
        type: 'payment_policy',
        title: 'Nacini placanja',
        content: [
            'DOSTUPNI NACINI PLACANJA',
            '- Pouzece (COD): [da/ne].',
            '- Online placanje karticom: [da/ne].',
            '- Uplata na racun: [da/ne].',
            '',
            'NAPOMENE',
            '- Ako online placanje nije dostupno, asistent treba ponuditi pouzece.',
            '- Asistent mora potvrditi nacin placanja prije finalne potvrde narudzbe.',
        ].join('\n'),
    },
    sales_playbook: {
        type: 'sales_playbook',
        title: 'Prodajni playbook asistenta',
        content: [
            'CILJ RAZGOVORA',
            '- Pomoci kupcu da brzo dodje do najboljeg izbora, bez forsiranja.',
            '',
            'OBAVEZNA PITANJA PRI PREPORUCI',
            '- Budzet (opseg cijene)',
            '- Namjena / problem koji kupac rjesava',
            '- Tip korisnika (npr. tip koze, iskustvo, preferencije)',
            '',
            'NACIN ODGOVORA',
            '- Predlozi do 3 proizvoda.',
            '- Za svaki proizvod kratko objasni zasto je dobar fit.',
            '- Ako nema savrsenog proizvoda, ponuditi najblizu opciju i traziti dodatno pojasnjenje.',
            '',
            'CTA',
            '- Nakon preporuke pozvati kupca na sljedeci korak: dodavanje u korpu ili checkout.',
        ].join('\n'),
    },
};

const KNOWLEDGE_TYPE_GUIDES = {
    faq: {
        purpose: 'Koristi za najcesca pitanja kupaca koja se ponavljaju svaki dan.',
        checklist: [
            'Pisi u formatu: PITANJE / ODGOVOR.',
            'Odgovor treba biti kratak, jasan i bez internog zargona.',
            'Ako postoji uslov (iznos, rok, lokacija), navedi ga tacno.',
            'Ako se pravilo razlikuje po kategoriji, razdvoji stavke.',
        ],
        exampleTitle: 'FAQ - Narudzbe i podrska',
        exampleContent: [
            'PITANJE: Koliko traje dostava?',
            'ODGOVOR: Standardna dostava traje 1-3 radna dana.',
            '',
            'PITANJE: Mogu li platiti pouzecem?',
            'ODGOVOR: Da, placanje pouzecem je dostupno u BiH.',
            '',
            'PITANJE: Kako kontaktirati podrsku?',
            'ODGOVOR: Pisite na podrska@firma.ba ili pozovite +387 33 000 000.',
        ].join('\n'),
    },
    shipping_policy: {
        purpose: 'Koristi za sve informacije o dostavi, rokovima i cijenama isporuke.',
        checklist: [
            'Navedi podrucja dostave (gradovi/drzave).',
            'Upisi tacan rok isporuke i izuzetke (vikend/praznici).',
            'Navedi cijenu dostave i prag za besplatnu dostavu.',
            'Dodaj sta se desava ako kupac nije dostupan na adresi.',
        ],
        exampleTitle: 'Dostava i rokovi',
        exampleContent: KNOWLEDGE_TEMPLATE_PRESETS.shipping_policy.content,
    },
    returns_policy: {
        purpose: 'Koristi za pravila povrata, reklamacija i refund procesa.',
        checklist: [
            'Definisi rok za povrat.',
            'Nabroji uslove koje proizvod mora ispuniti za povrat.',
            'Objasni kome i kako se salje reklamacija.',
            'Navedi rok i nacin povrata novca.',
        ],
        exampleTitle: 'Povrat i reklamacije',
        exampleContent: KNOWLEDGE_TEMPLATE_PRESETS.returns_policy.content,
    },
    promotions: {
        purpose: 'Koristi za aktivne akcije, popuste, kupone i pravila kombinovanja.',
        checklist: [
            'Za svaku akciju navedi naziv, procenat/iznos i period trajanja.',
            'Navedi da li se kupon kombinira sa vec snizenim artiklima.',
            'Naglasi minimalni iznos korpe ako postoji.',
            'Dodaj napomenu da akcija vrijedi do isteka zaliha ako je primjenjivo.',
        ],
        exampleTitle: 'Akcije i kupon pravila',
        exampleContent: KNOWLEDGE_TEMPLATE_PRESETS.promotions.content,
    },
    payment_policy: {
        purpose: 'Koristi za nacine placanja i pravila po nacinu placanja.',
        checklist: [
            'Navedi dostupne nacine placanja.',
            'Ako online placanje nije dostupno, jasno navedi alternativu (pouzece).',
            'Ako postoji dodatna naknada, navedi je.',
            'Ako postoje ogranicenja po drzavi/regiji, navedi ih.',
        ],
        exampleTitle: 'Nacini placanja',
        exampleContent: KNOWLEDGE_TEMPLATE_PRESETS.payment_policy.content,
    },
    sales_playbook: {
        purpose: 'Koristi za stil razgovora i korake koje asistent treba pratiti kao prodavac.',
        checklist: [
            'Definisi ton komunikacije (prijateljski, nenametljiv, strucan).',
            'Nabroji obavezna pitanja prije preporuke.',
            'Definisi kako se predstavljaju preporuke (max 3, sa razlogom).',
            'Definisi kada asistent trazi dodatne informacije umjesto pogadjanja.',
        ],
        exampleTitle: 'Prodajni playbook asistenta',
        exampleContent: KNOWLEDGE_TEMPLATE_PRESETS.sales_playbook.content,
    },
    product_education: {
        purpose: 'Koristi za edukativne smjernice kako kupac da izabere pravi proizvod.',
        checklist: [
            'Opisi tipicne profile kupaca i njihove potrebe.',
            'Objasni razlike medju kategorijama proizvoda.',
            'Navedi jednostavne kriterije izbora (tip koze, problem, budzet).',
            'Dodaj preporuku kako kombinovati proizvode kada je relevantno.',
        ],
        exampleTitle: 'Kako odabrati proizvod za suhu kozu',
        exampleContent: [
            'ZA KOGA JE OVAJ VODIC',
            '- Kupci sa suhom i osjetljivom kozom koji traze hidrataciju.',
            '',
            'STA PITATI KUPCA',
            '- Da li je koza zategnuta nakon umivanja?',
            '- Da li postoje iritacije ili crvenilo?',
            '- Koji je budzet?',
            '',
            'PREPORUKA IZBORA',
            '- Za dnevnu rutinu birati lagane hidratantne formule.',
            '- Za nocnu rutinu birati bogatije kreme ili serum + krema kombinaciju.',
        ].join('\n'),
    },
    company_info: {
        purpose: 'Koristi za osnovne informacije o firmi i kontakt podatke.',
        checklist: [
            'Navedi pun naziv firme i osnovni opis.',
            'Navedi kontakt email, telefon i radno vrijeme.',
            'Ako postoji fizicka lokacija, navedi adresu.',
            'Ako postoji posebna podrska (Viber/WhatsApp), navedi i to.',
        ],
        exampleTitle: 'Informacije o firmi i kontakt',
        exampleContent: [
            'O NAMA',
            '- [Naziv firme] je specijaliziran za [kategorija proizvoda/usluga].',
            '',
            'KONTAKT',
            '- Email: info@firma.ba',
            '- Telefon: +387 33 000 000',
            '- Radno vrijeme: Pon-Pet 08:00-17:00',
            '',
            'ADRESA',
            '- Ulica i broj, postanski broj, grad.',
        ].join('\n'),
    },
};

const elements = {
    alert: document.getElementById('global-alert'),
    sessionStatus: document.getElementById('session-status'),
    logoutButton: document.getElementById('logout-button'),
    loginCard: document.getElementById('login-card'),
    loggedInCard: document.getElementById('logged-in-card'),
    loggedInName: document.getElementById('logged-in-name'),
    loggedInEmail: document.getElementById('logged-in-email'),
    loggedInTenant: document.getElementById('logged-in-tenant'),
    loggedInRole: document.getElementById('logged-in-role'),
    loggedInLogoutButton: document.getElementById('logged-in-logout-button'),
    sidebarNav: document.getElementById('sidebar-nav'),
    mainContent: document.getElementById('app-main-content'),
    navButtons: Array.from(document.querySelectorAll('.nav-button')),
    viewPanels: Array.from(document.querySelectorAll('.view-panel')),

    loginForm: document.getElementById('login-form'),
    loginEmail: document.getElementById('login-email'),
    loginPassword: document.getElementById('login-password'),
    forgotPasswordForm: document.getElementById('forgot-password-form'),
    forgotPasswordEmail: document.getElementById('forgot-password-email'),

    refreshOverview: document.getElementById('refresh-overview'),
    statConversations: document.getElementById('stat-conversations'),
    statLeads: document.getElementById('stat-leads'),
    statEvents: document.getElementById('stat-events'),
    statLeadRate: document.getElementById('stat-lead-rate'),
    topEventsList: document.getElementById('top-events-list'),

    onboardingForm: document.getElementById('onboarding-form'),
    onboardingSteps: Array.from(document.querySelectorAll('.wizard-step')),
    onboardingPanels: Array.from(document.querySelectorAll('.wizard-panel')),
    onboardingPrev: document.getElementById('onboarding-prev'),
    onboardingNext: document.getElementById('onboarding-next'),
    onboardingSubmit: document.getElementById('onboarding-submit'),
    onboardingTenantName: document.getElementById('onboarding-tenant-name'),
    onboardingTenantSlug: document.getElementById('onboarding-tenant-slug'),
    onboardingOwnerName: document.getElementById('onboarding-owner-name'),
    onboardingOwnerEmail: document.getElementById('onboarding-owner-email'),
    onboardingOwnerPassword: document.getElementById('onboarding-owner-password'),
    onboardingOwnerPasswordConfirmation: document.getElementById('onboarding-owner-password-confirmation'),
    onboardingWidgetName: document.getElementById('onboarding-widget-name'),
    onboardingWidgetLocale: document.getElementById('onboarding-widget-locale'),
    onboardingWidgetDomains: document.getElementById('onboarding-widget-domains'),
    onboardingWidgetTheme: document.getElementById('onboarding-widget-theme'),
    onboardingIntegrationEnabled: document.getElementById('onboarding-integration-enabled'),
    onboardingIntegrationName: document.getElementById('onboarding-integration-name'),
    onboardingIntegrationType: document.getElementById('onboarding-integration-type'),
    onboardingIntegrationGuide: document.getElementById('onboarding-integration-guide'),
    onboardingIntegrationApplyTemplate: document.getElementById('onboarding-integration-apply-template'),
    onboardingIntegrationBaseUrl: document.getElementById('onboarding-integration-base-url'),
    onboardingIntegrationAuthType: document.getElementById('onboarding-integration-auth-type'),
    onboardingIntegrationCredentials: document.getElementById('onboarding-integration-credentials'),
    onboardingIntegrationConfig: document.getElementById('onboarding-integration-config'),
    onboardingIntegrationMapping: document.getElementById('onboarding-integration-mapping'),
    onboardingAiProvider: document.getElementById('onboarding-ai-provider'),
    onboardingAiModel: document.getElementById('onboarding-ai-model'),
    onboardingAiEmbedding: document.getElementById('onboarding-ai-embedding'),
    onboardingAiTemperature: document.getElementById('onboarding-ai-temperature'),
    onboardingAiMaxTokens: document.getElementById('onboarding-ai-max-tokens'),

    tenantsLoad: document.getElementById('tenants-load'),
    tenantsTableBody: document.getElementById('tenants-table-body'),

    usersLoad: document.getElementById('users-load'),
    userCreateForm: document.getElementById('user-create-form'),
    userName: document.getElementById('user-name'),
    userEmail: document.getElementById('user-email'),
    userPassword: document.getElementById('user-password'),
    userPasswordConfirmation: document.getElementById('user-password-confirmation'),
    userRole: document.getElementById('user-role'),
    userSystemAdmin: document.getElementById('user-system-admin'),
    userSystemAdminWrap: document.getElementById('user-system-admin-wrap'),
    usersTableBody: document.getElementById('users-table-body'),

    integrationsLoad: document.getElementById('integrations-load'),
    integrationsTableBody: document.getElementById('integrations-table-body'),
    integrationCreateForm: document.getElementById('integration-create-form'),
    integrationName: document.getElementById('integration-name'),
    integrationType: document.getElementById('integration-type'),
    integrationQuickGuide: document.getElementById('integration-quick-guide'),
    integrationApplyTemplate: document.getElementById('integration-apply-template'),
    integrationBaseUrl: document.getElementById('integration-base-url'),
    integrationSyncFrequency: document.getElementById('integration-sync-frequency'),
    integrationAuthType: document.getElementById('integration-auth-type'),
    integrationCredentials: document.getElementById('integration-credentials'),
    integrationConfig: document.getElementById('integration-config'),
    integrationMapping: document.getElementById('integration-mapping'),

    presetCreateForm: document.getElementById('preset-create-form'),
    presetIntegrationId: document.getElementById('preset-integration-id'),
    presetName: document.getElementById('preset-name'),
    presetMapping: document.getElementById('preset-mapping'),
    presetsTableBody: document.getElementById('presets-table-body'),

    widgetsLoad: document.getElementById('widgets-load'),
    widgetCreateForm: document.getElementById('widget-create-form'),
    widgetCreateName: document.getElementById('widget-create-name'),
    widgetCreateLocale: document.getElementById('widget-create-locale'),
    widgetCreateDomains: document.getElementById('widget-create-domains'),
    widgetCreateTheme: document.getElementById('widget-create-theme'),
    widgetCreateActive: document.getElementById('widget-create-active'),
    widgetsTableBody: document.getElementById('widgets-table-body'),

    productCreateForm: document.getElementById('product-create-form'),
    productFilterForm: document.getElementById('product-filter-form'),
    productSearch: document.getElementById('product-search'),
    productStatus: document.getElementById('product-status'),
    productName: document.getElementById('product-name'),
    productSku: document.getElementById('product-sku'),
    productPrice: document.getElementById('product-price'),
    productCategory: document.getElementById('product-category'),
    productBrand: document.getElementById('product-brand'),
    productUrl: document.getElementById('product-url'),
    productInStock: document.getElementById('product-in-stock'),
    productsTableBody: document.getElementById('products-table-body'),
    productsPagePrev: document.getElementById('products-page-prev'),
    productsPageNext: document.getElementById('products-page-next'),
    productsPageInfo: document.getElementById('products-page-info'),
    productsPerPage: document.getElementById('products-per-page'),
    productsTotalInfo: document.getElementById('products-total-info'),
    productsDeleteAll: document.getElementById('products-delete-all'),

    knowledgeLoad: document.getElementById('knowledge-load'),
    knowledgeTextForm: document.getElementById('knowledge-text-form'),
    knowledgeTitle: document.getElementById('knowledge-title'),
    knowledgeType: document.getElementById('knowledge-type'),
    knowledgeTypeHelp: document.getElementById('knowledge-type-help'),
    knowledgeVisibility: document.getElementById('knowledge-visibility'),
    knowledgeAiAllowed: document.getElementById('knowledge-ai-allowed'),
    knowledgeTemplatePreset: document.getElementById('knowledge-template-preset'),
    knowledgeApplyTemplate: document.getElementById('knowledge-apply-template'),
    knowledgeContent: document.getElementById('knowledge-content'),
    knowledgeTableBody: document.getElementById('knowledge-table-body'),
    knowledgeGuideTabs: document.getElementById('knowledge-guide-tabs'),
    knowledgeGuideCurrentType: document.getElementById('knowledge-guide-current-type'),
    knowledgeGuidePurpose: document.getElementById('knowledge-guide-purpose'),
    knowledgeGuideChecklist: document.getElementById('knowledge-guide-checklist'),
    knowledgeGuideUseType: document.getElementById('knowledge-guide-use-type'),
    knowledgeGuideInsertExample: document.getElementById('knowledge-guide-insert-example'),
    knowledgeGuideExampleTitle: document.getElementById('knowledge-guide-example-title'),
    knowledgeGuideExampleContent: document.getElementById('knowledge-guide-example-content'),

    conversationsLoad: document.getElementById('conversations-load'),
    conversationsTableBody: document.getElementById('conversations-table-body'),
    conversationMessages: document.getElementById('conversation-messages'),

    importsLoad: document.getElementById('imports-load'),
    importsTableBody: document.getElementById('imports-table-body'),

    auditLoad: document.getElementById('audit-load'),
    auditFilterForm: document.getElementById('audit-filter-form'),
    auditFilterAction: document.getElementById('audit-filter-action'),
    auditFilterEntity: document.getElementById('audit-filter-entity'),
    auditFilterUser: document.getElementById('audit-filter-user'),
    auditTableBody: document.getElementById('audit-table-body'),
    widgetAbuseLoad: document.getElementById('widget-abuse-load'),
    widgetAbuseFilterForm: document.getElementById('widget-abuse-filter-form'),
    widgetAbuseFilterReason: document.getElementById('widget-abuse-filter-reason'),
    widgetAbuseFilterIp: document.getElementById('widget-abuse-filter-ip'),
    widgetAbuseFilterPublicKey: document.getElementById('widget-abuse-filter-public-key'),
    widgetAbuseTableBody: document.getElementById('widget-abuse-table-body'),
    orderStatusLoad: document.getElementById('order-status-load'),
    orderStatusFilterForm: document.getElementById('order-status-filter-form'),
    orderStatusFilterStatus: document.getElementById('order-status-filter-status'),
    orderStatusFilterProvider: document.getElementById('order-status-filter-provider'),
    orderStatusFilterOrderId: document.getElementById('order-status-filter-order-id'),
    orderStatusTableBody: document.getElementById('order-status-table-body'),

    aiConfigForm: document.getElementById('ai-config-form'),
    aiProvider: document.getElementById('ai-provider'),
    aiModelName: document.getElementById('ai-model-name'),
    aiEmbeddingModel: document.getElementById('ai-embedding-model'),
    aiTemperature: document.getElementById('ai-temperature'),
    aiMaxTokens: document.getElementById('ai-max-tokens'),
    aiMaxMessagesMonthly: document.getElementById('ai-max-messages-monthly'),
    aiMaxTokensDaily: document.getElementById('ai-max-tokens-daily'),
    aiMaxTokensMonthly: document.getElementById('ai-max-tokens-monthly'),
    aiBlockOnLimit: document.getElementById('ai-block-on-limit'),
    aiAlertOnLimit: document.getElementById('ai-alert-on-limit'),
    aiTopP: document.getElementById('ai-top-p'),
    aiSystemPrompt: document.getElementById('ai-system-prompt'),
    aiUsageSummary: document.getElementById('ai-usage-summary'),

    widgetSessionForm: document.getElementById('widget-session-form'),
    widgetMessageForm: document.getElementById('widget-message-form'),
    widgetPublicKey: document.getElementById('widget-public-key'),
    widgetSourceUrl: document.getElementById('widget-source-url'),
    widgetMessage: document.getElementById('widget-message'),
    widgetChatLog: document.getElementById('widget-chat-log'),

    entityModal: document.getElementById('entity-modal'),
    entityModalTitle: document.getElementById('entity-modal-title'),
    entityModalClose: document.getElementById('entity-modal-close'),
    entityModalForm: document.getElementById('entity-modal-form'),
    entityModalFields: document.getElementById('entity-modal-fields'),

    confirmModal: document.getElementById('confirm-modal'),
    confirmModalMessage: document.getElementById('confirm-modal-message'),
    confirmCancel: document.getElementById('confirm-cancel'),
    confirmAccept: document.getElementById('confirm-accept'),
};

init().catch((error) => {
    showAlert(error.message || 'Frontend initialization failed.', 'error');
});

async function init() {
    restoreSession();
    bindNavigation();
    bindAuth();
    bindOverview();
    bindOnboarding();
    bindTenants();
    bindUsers();
    bindIntegrations();
    bindWidgets();
    bindProducts();
    bindKnowledge();
    bindConversations();
    bindImports();
    bindAuditLogs();
    bindWidgetAbuseLogs();
    bindOrderStatusEvents();
    bindAiConfig();
    bindWidgetLab();
    bindModalInfrastructure();
    renderSessionStatus();

    if (state.token && state.tenantSlug) {
        await bootstrapAuthenticatedState();
    }
}

function bindNavigation() {
    elements.navButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const view = button.dataset.view;
            if (!view) {
                return;
            }
            activateView(view);
        });
    });
}

function activateView(view) {
    if (!state.token || !state.tenantSlug) {
        return;
    }

    if (!canAccessView(view)) {
        return;
    }

    elements.navButtons.forEach((button) => {
        button.classList.toggle('active', button.dataset.view === view);
    });
    elements.viewPanels.forEach((panel) => {
        panel.classList.toggle('active', panel.id === `view-${view}`);
    });
}

function bindOverview() {
    elements.refreshOverview?.addEventListener('click', () => {
        withGuard(loadOverview);
    });
}

function bindOnboarding() {
    bindOnboardingIntegrationAutoEnable();
    bindIntegrationAdvancedToggle('onboarding');

    elements.onboardingIntegrationType?.addEventListener('change', () => {
        applyOnboardingIntegrationGuide(elements.onboardingIntegrationType.value, false);
    });

    document.getElementById('onboarding-wp-auth-mode')?.addEventListener('change', () => {
        applySimpleAuthModeVisibility('onboarding', elements.onboardingIntegrationType?.value ?? '');
    });
    document.getElementById('onboarding-custom-auth-mode')?.addEventListener('change', () => {
        applySimpleAuthModeVisibility('onboarding', elements.onboardingIntegrationType?.value ?? '');
    });

    elements.onboardingIntegrationApplyTemplate?.addEventListener('click', () => {
        applyOnboardingIntegrationGuide(elements.onboardingIntegrationType?.value ?? 'woocommerce', true);
        showAlert('Onboarding integration template je primijenjen.', 'ok');
    });

    elements.onboardingSteps.forEach((stepButton) => {
        stepButton.addEventListener('click', () => {
            const step = Number(stepButton.dataset.step);
            if (!Number.isFinite(step)) {
                return;
            }
            if (step > state.onboardingStep && !validateOnboardingStep(state.onboardingStep)) {
                return;
            }
            setOnboardingStep(step);
        });
    });

    elements.onboardingPrev?.addEventListener('click', () => {
        setOnboardingStep(state.onboardingStep - 1);
    });

    elements.onboardingNext?.addEventListener('click', () => {
        if (!validateOnboardingStep(state.onboardingStep)) {
            return;
        }
        setOnboardingStep(state.onboardingStep + 1);
    });

    elements.onboardingForm?.addEventListener('submit', async (event) => {
        event.preventDefault();

        try {
            if (!elements.onboardingForm.reportValidity()) {
                return;
            }

            const payload = {
                tenant_name: elements.onboardingTenantName.value.trim(),
                tenant_slug: emptyToUndefined(elements.onboardingTenantSlug.value),
                owner_name: elements.onboardingOwnerName.value.trim(),
                owner_email: elements.onboardingOwnerEmail.value.trim(),
                owner_password: elements.onboardingOwnerPassword.value,
                owner_password_confirmation: elements.onboardingOwnerPasswordConfirmation.value,
                widget: compactPayload({
                    name: emptyToUndefined(elements.onboardingWidgetName.value),
                    default_locale: emptyToUndefined(elements.onboardingWidgetLocale.value),
                    allowed_domains_json: parseJsonField(elements.onboardingWidgetDomains.value, 'Widget domains'),
                    theme_json: parseJsonField(elements.onboardingWidgetTheme.value, 'Widget theme'),
                }),
                ai_config: compactPayload({
                    provider: emptyToUndefined(elements.onboardingAiProvider.value),
                    model_name: emptyToUndefined(elements.onboardingAiModel.value),
                    embedding_model: emptyToUndefined(elements.onboardingAiEmbedding.value),
                    temperature: numberOrUndefined(elements.onboardingAiTemperature.value),
                    max_output_tokens: intOrUndefined(elements.onboardingAiMaxTokens.value),
                }),
            };

            const response = await request('/api/onboarding/bootstrap', {
                method: 'POST',
                body: payload,
            });

            const data = response.data ?? {};
            const createdTenantName = String(data.tenant?.name ?? payload.tenant_name ?? 'tenant');
            const createdTenantSlug = String(data.tenant?.slug ?? payload.tenant_slug ?? '');

            elements.onboardingForm.reset();
            if (elements.onboardingWidgetName) elements.onboardingWidgetName.value = 'Main Widget';
            if (elements.onboardingWidgetLocale) elements.onboardingWidgetLocale.value = 'bs';
            if (elements.onboardingIntegrationName) elements.onboardingIntegrationName.value = 'Primary Source';
            if (elements.onboardingAiProvider) elements.onboardingAiProvider.value = 'openai';
            if (elements.onboardingAiModel) elements.onboardingAiModel.value = 'gpt-5-mini';
            if (elements.onboardingAiEmbedding) elements.onboardingAiEmbedding.value = 'text-embedding-3-small';
            if (elements.onboardingAiTemperature) elements.onboardingAiTemperature.value = '0.3';
            if (elements.onboardingAiMaxTokens) elements.onboardingAiMaxTokens.value = '350';
            setOnboardingStep(1);

            const tenantLabel = createdTenantSlug !== ''
                ? `${createdTenantName} (${createdTenantSlug})`
                : createdTenantName;
            showAlert(`Tenant kreiran: ${tenantLabel}. Integracije tenant podesava kasnije nakon login-a.`, 'ok');
            await bootstrapAuthenticatedState();
            activateView('tenants');
        } catch (error) {
            showAlert(error.message, 'error');
        }
    });

    setOnboardingStep(1);
    applyOnboardingIntegrationGuide(elements.onboardingIntegrationType?.value ?? 'woocommerce', false);
}

function bindOnboardingIntegrationAutoEnable() {
    const ids = [
        'onboarding-integration-base-url',
        'onboarding-integration-auth-type',
        'onboarding-integration-credentials',
        'onboarding-integration-config',
        'onboarding-integration-mapping',
        'onboarding-wp-auth-mode',
        'onboarding-custom-auth-mode',
        'onboarding-woo-consumer-key',
        'onboarding-woo-consumer-secret',
        'onboarding-wp-username',
        'onboarding-wp-app-password',
        'onboarding-wp-token',
        'onboarding-wp-resource-path',
        'onboarding-shopify-access-token',
        'onboarding-custom-token',
        'onboarding-custom-username',
        'onboarding-custom-password',
        'onboarding-custom-api-key',
        'onboarding-custom-api-key-query',
        'onboarding-custom-products-endpoint',
    ];

    ids.forEach((id) => {
        const input = document.getElementById(id);
        if (!input) {
            return;
        }

        const markEnabled = () => {
            const value = String(input.value ?? '').trim();
            if (value !== '' && elements.onboardingIntegrationEnabled) {
                elements.onboardingIntegrationEnabled.checked = true;
            }
        };

        input.addEventListener('input', markEnabled);
        input.addEventListener('change', markEnabled);
    });
}

function setOnboardingStep(step) {
    const normalized = Math.max(1, Math.min(ONBOARDING_MAX_STEP, step));
    state.onboardingStep = normalized;

    elements.onboardingSteps.forEach((button) => {
        button.classList.toggle('active', Number(button.dataset.step) === normalized);
    });
    elements.onboardingPanels.forEach((panel) => {
        panel.classList.toggle('active', Number(panel.dataset.step) === normalized);
    });

    if (elements.onboardingPrev) {
        elements.onboardingPrev.disabled = normalized === 1;
    }
    if (elements.onboardingNext) {
        elements.onboardingNext.disabled = normalized === ONBOARDING_MAX_STEP;
    }
    if (elements.onboardingSubmit) {
        elements.onboardingSubmit.classList.toggle('hidden', normalized !== ONBOARDING_MAX_STEP);
    }
}

function validateOnboardingStep(step) {
    const panel = elements.onboardingPanels.find((item) => Number(item.dataset.step) === Number(step));
    if (!panel) {
        return true;
    }

    const controls = Array.from(panel.querySelectorAll('input, select, textarea'));
    for (const control of controls) {
        if (typeof control.reportValidity === 'function' && !control.reportValidity()) {
            return false;
        }
    }

    return true;
}

function buildOnboardingIntegrationPayload() {
    const type = emptyToUndefined(elements.onboardingIntegrationType?.value);
    const normalizedType = String(type ?? '').trim().toLowerCase();
    const allowAdvanced = integrationSupportsSimpleSetup(normalizedType);
    const baseUrl = emptyToUndefined(elements.onboardingIntegrationBaseUrl?.value);
    const requestedAuthType = allowAdvanced
        ? emptyToUndefined(elements.onboardingIntegrationAuthType?.value)
        : undefined;

    const advancedCredentials = allowAdvanced
        ? parseJsonField(
            elements.onboardingIntegrationCredentials?.value ?? '',
            'Integration credentials',
        )
        : undefined;
    const advancedConfig = allowAdvanced
        ? parseJsonField(
            elements.onboardingIntegrationConfig?.value ?? '',
            'Integration config',
        )
        : undefined;
    const advancedMapping = allowAdvanced
        ? parseJsonField(
            elements.onboardingIntegrationMapping?.value ?? '',
            'Integration mapping',
        )
        : undefined;

    const simple = buildSimpleIntegrationArtifacts('onboarding', normalizedType || 'manual', requestedAuthType);

    const credentials = mergePlainObjects(advancedCredentials, simple.credentials);
    const configJson = mergePlainObjects(advancedConfig, simple.config_json);
    const mappingJson = mergePlainObjects(advancedMapping, simple.mapping_json);

    const explicitEnabled = Boolean(elements.onboardingIntegrationEnabled?.checked);
    const autoEnabled = Boolean(baseUrl || credentials || configJson || mappingJson);
    const enabled = explicitEnabled || autoEnabled;

    if (enabled) {
        validateIntegrationRequiredFields(type ?? 'manual', baseUrl, simple.auth_type ?? requestedAuthType, credentials, configJson);
    }

    return {
        enabled,
        name: emptyToUndefined(elements.onboardingIntegrationName?.value),
        type,
        base_url: baseUrl,
        auth_type: emptyToUndefined(simple.auth_type ?? requestedAuthType),
        credentials,
        config_json: configJson,
        mapping_json: mappingJson,
    };
}

function buildIntegrationPayload() {
    const type = emptyToUndefined(elements.integrationType?.value);
    const normalizedType = String(type ?? '').trim().toLowerCase();
    const allowAdvanced = integrationSupportsSimpleSetup(normalizedType);
    const baseUrl = emptyToUndefined(elements.integrationBaseUrl?.value);
    const requestedAuthType = allowAdvanced ? emptyToUndefined(elements.integrationAuthType?.value) : undefined;

    const advancedCredentials = allowAdvanced
        ? parseJsonField(
            elements.integrationCredentials?.value ?? '',
            'Credentials JSON',
        )
        : undefined;
    const advancedConfig = allowAdvanced
        ? parseJsonField(
            elements.integrationConfig?.value ?? '',
            'Config JSON',
        )
        : undefined;
    const advancedMapping = allowAdvanced
        ? parseJsonField(
            elements.integrationMapping?.value ?? '',
            'Mapping JSON',
        )
        : undefined;

    const simple = buildSimpleIntegrationArtifacts('integration', normalizedType || 'manual', requestedAuthType);
    const authType = emptyToUndefined(simple.auth_type ?? requestedAuthType);
    const credentials = mergePlainObjects(advancedCredentials, simple.credentials);
    const configJson = mergePlainObjects(advancedConfig, simple.config_json);
    const mappingJson = mergePlainObjects(advancedMapping, simple.mapping_json);

    validateIntegrationRequiredFields(type ?? 'manual', baseUrl, authType, credentials, configJson);

    return {
        base_url: baseUrl,
        auth_type: authType,
        credentials,
        config_json: configJson,
        mapping_json: mappingJson,
    };
}

function validateIntegrationRequiredFields(type, baseUrl, authTypeRaw, credentialsRaw, configRaw) {
    const normalizedType = String(type ?? '').trim().toLowerCase();
    const authType = String(authTypeRaw ?? '').trim().toLowerCase();
    const credentials = isPlainObject(credentialsRaw) ? credentialsRaw : {};
    const config = isPlainObject(configRaw) ? configRaw : {};

    if (['woocommerce', 'wordpress_rest', 'shopify', 'custom_api'].includes(normalizedType) && !baseUrl) {
        throw new Error('Base URL je obavezan za izabrani integration type.');
    }

    if (normalizedType === 'woocommerce') {
        if (!credentials.consumer_key || !credentials.consumer_secret) {
            throw new Error('WooCommerce zahtijeva Consumer Key i Consumer Secret.');
        }
    } else if (normalizedType === 'wordpress_rest') {
        if (!config.resource_path) {
            throw new Error('WordPress REST zahtijeva Resource Path (npr. /wp-json/wp/v2/posts).');
        }
        if (authType === 'basic' && (!credentials.username || !credentials.password)) {
            throw new Error('WordPress basic auth zahtijeva username i app password.');
        }
        if (authType === 'bearer' && !credentials.token) {
            throw new Error('WordPress bearer auth zahtijeva token.');
        }
    } else if (normalizedType === 'shopify') {
        if (!credentials.access_token) {
            throw new Error('Shopify zahtijeva Access Token.');
        }
    } else if (normalizedType === 'custom_api') {
        if (!config.products_endpoint) {
            throw new Error('Custom API zahtijeva Products endpoint (npr. /products).');
        }
        if (authType === 'basic' && (!credentials.username || !credentials.password)) {
            throw new Error('Custom API basic auth zahtijeva username i password.');
        }
        if (authType === 'bearer' && !credentials.token) {
            throw new Error('Custom API bearer auth zahtijeva token.');
        }
        if (authType === 'api_key_header' && !credentials.api_key) {
            throw new Error('Custom API api_key_header zahtijeva API key.');
        }
        if (authType === 'api_key_query' && !credentials.api_key) {
            throw new Error('Custom API api_key_query zahtijeva API key.');
        }
    }
}

function buildSimpleIntegrationArtifacts(scope, type, requestedAuthType) {
    const normalizedType = String(type ?? '').trim().toLowerCase();
    let authType = String(requestedAuthType ?? '').trim().toLowerCase();
    const credentials = {};
    const config = {};
    const mapping = {};

    if (normalizedType === 'woocommerce') {
        const consumerKey = scopedIntegrationValue(scope, 'woo-consumer-key');
        const consumerSecret = scopedIntegrationValue(scope, 'woo-consumer-secret');
        if (consumerKey) {
            credentials.consumer_key = consumerKey;
        }
        if (consumerSecret) {
            credentials.consumer_secret = consumerSecret;
        }
        if (authType === '') {
            authType = 'woocommerce_key_secret';
        }
    } else if (normalizedType === 'wordpress_rest') {
        const selectedMode = scopedIntegrationValue(scope, 'wp-auth-mode');
        const username = scopedIntegrationValue(scope, 'wp-username');
        const password = scopedIntegrationValue(scope, 'wp-app-password');
        const bearer = scopedIntegrationValue(scope, 'wp-token');
        const resourcePath = scopedIntegrationValue(scope, 'wp-resource-path');
        const hasBasicCreds = Boolean(username && password);
        const hasBearerToken = Boolean(bearer);

        if (resourcePath) {
            config.resource_path = resourcePath;
        }

        if (selectedMode) {
            authType = selectedMode;
        } else if (hasBearerToken && !hasBasicCreds) {
            authType = 'bearer';
        } else if (hasBasicCreds && !hasBearerToken) {
            authType = 'basic';
        }

        if (authType === '') {
            if (hasBearerToken) {
                authType = 'bearer';
            } else if (hasBasicCreds) {
                authType = 'basic';
            } else {
                authType = 'none';
            }
        }

        if (authType === 'basic' && hasBasicCreds) {
            credentials.username = username;
            credentials.password = password;
        } else if (authType === 'bearer' && bearer) {
            credentials.token = bearer;
        }
    } else if (normalizedType === 'shopify') {
        const accessToken = scopedIntegrationValue(scope, 'shopify-access-token');

        if (accessToken) {
            credentials.access_token = accessToken;
        }
        if (authType === '') {
            authType = 'shopify_token';
        }
    } else if (normalizedType === 'custom_api') {
        const selectedMode = scopedIntegrationValue(scope, 'custom-auth-mode');
        const token = scopedIntegrationValue(scope, 'custom-token');
        const username = scopedIntegrationValue(scope, 'custom-username');
        const password = scopedIntegrationValue(scope, 'custom-password');
        const apiKey = scopedIntegrationValue(scope, 'custom-api-key');
        const apiKeyQuery = scopedIntegrationValue(scope, 'custom-api-key-query');
        const productsEndpoint = scopedIntegrationValue(scope, 'custom-products-endpoint');
        const hasBearerToken = Boolean(token);
        const hasBasicCreds = Boolean(username && password);
        const hasApiHeaderKey = Boolean(apiKey);
        const hasApiQueryKey = Boolean(apiKeyQuery);

        if (productsEndpoint) {
            config.products_endpoint = productsEndpoint;
        }

        if (selectedMode) {
            authType = selectedMode;
        } else if (hasBearerToken && !hasBasicCreds && !hasApiHeaderKey && !hasApiQueryKey) {
            authType = 'bearer';
        } else if (hasBasicCreds && !hasBearerToken && !hasApiHeaderKey && !hasApiQueryKey) {
            authType = 'basic';
        } else if (hasApiHeaderKey && !hasBasicCreds && !hasBearerToken) {
            authType = 'api_key_header';
        } else if (hasApiQueryKey && !hasBasicCreds && !hasBearerToken) {
            authType = 'api_key_query';
        }

        if (authType === '') {
            if (hasBearerToken) {
                authType = 'bearer';
            } else if (hasBasicCreds) {
                authType = 'basic';
            } else if (hasApiHeaderKey) {
                authType = 'api_key_header';
            } else if (hasApiQueryKey) {
                authType = 'api_key_query';
            }
        }

        if (authType === 'basic' && hasBasicCreds) {
            credentials.username = username;
            credentials.password = password;
        } else if (authType === 'bearer') {
            if (token) {
                credentials.token = token;
            }
        } else if (authType === 'api_key_header') {
            if (apiKey) {
                credentials.api_key = apiKey;
            }
            credentials.header_name = 'X-API-Key';
        } else if (authType === 'api_key_query') {
            if (apiKeyQuery) {
                credentials.api_key = apiKeyQuery;
            }
            credentials.query_param = 'api_key';
        }
    }

    return {
        auth_type: authType === '' ? undefined : authType,
        credentials: emptyObjectToUndefined(credentials),
        config_json: emptyObjectToUndefined(config),
        mapping_json: emptyObjectToUndefined(mapping),
    };
}

function scopedIntegrationValue(scope, suffix) {
    const element = document.getElementById(`${scope}-${suffix}`);
    return emptyToUndefined(element?.value);
}

function mergePlainObjects(base, extra) {
    const left = isPlainObject(base) ? { ...base } : {};
    const right = isPlainObject(extra) ? { ...extra } : {};
    const merged = { ...left, ...right };
    return emptyObjectToUndefined(merged);
}

function isPlainObject(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function emptyObjectToUndefined(value) {
    if (!isPlainObject(value)) {
        return undefined;
    }

    return Object.keys(value).length > 0 ? value : undefined;
}

function bindIntegrationAdvancedToggle(scope) {
    const toggle = document.getElementById(`${scope}-advanced-toggle`);
    const wrap = document.getElementById(`${scope}-advanced-wrap`);
    if (!toggle || !wrap) {
        return;
    }

    const fieldIds = [
        `${scope}-integration-auth-type`,
        `${scope}-integration-credentials`,
        `${scope}-integration-config`,
        `${scope}-integration-mapping`,
    ];

    const hasAdvancedValues = fieldIds.some((id) => {
        const input = document.getElementById(id);
        return String(input?.value ?? '').trim() !== '';
    });

    if (hasAdvancedValues) {
        wrap.classList.remove('hidden');
    }

    const syncText = () => {
        toggle.textContent = wrap.classList.contains('hidden') ? 'Show Advanced Options' : 'Hide Advanced Options';
    };

    toggle.addEventListener('click', () => {
        wrap.classList.toggle('hidden');
        syncText();
    });

    syncText();
}

function integrationSupportsSimpleSetup(type) {
    const normalizedType = String(type ?? '').trim().toLowerCase();
    return ['woocommerce', 'wordpress_rest', 'shopify', 'custom_api'].includes(normalizedType);
}

function integrationRequiresBaseUrl(type) {
    const normalizedType = String(type ?? '').trim().toLowerCase();
    return ['woocommerce', 'wordpress_rest', 'shopify', 'custom_api'].includes(normalizedType);
}

function integrationRequiredFields(scope, type) {
    const normalizedType = String(type ?? '').trim().toLowerCase();
    const fields = [];

    if (integrationRequiresBaseUrl(normalizedType)) {
        fields.push('Base URL');
    }

    if (normalizedType === 'woocommerce') {
        fields.push('Woo Consumer Key', 'Woo Consumer Secret');
    } else if (normalizedType === 'wordpress_rest') {
        fields.push('WordPress Resource Path');
        const mode = scopedIntegrationValue(scope, 'wp-auth-mode') ?? 'none';
        if (mode === 'basic') {
            fields.push('WP Username', 'WP App Password');
        } else if (mode === 'bearer') {
            fields.push('WP Bearer Token');
        }
    } else if (normalizedType === 'shopify') {
        fields.push('Shopify Access Token');
    } else if (normalizedType === 'custom_api') {
        fields.push('Products endpoint');
        const mode = scopedIntegrationValue(scope, 'custom-auth-mode') ?? 'bearer';
        if (mode === 'basic') {
            fields.push('API Username', 'API Password');
        } else if (mode === 'bearer') {
            fields.push('API Token');
        } else if (mode === 'api_key_header' || mode === 'api_key_query') {
            fields.push('API Key');
        }
    }

    return Array.from(new Set(fields));
}

function requiredFieldsElementForScope(scope) {
    if (scope === 'onboarding') {
        return document.getElementById('onboarding-required-fields');
    }
    if (scope === 'integration') {
        return document.getElementById('integration-required-fields');
    }
    return null;
}

function renderIntegrationRequiredLegend(scope, type) {
    const container = requiredFieldsElementForScope(scope);
    if (!container) {
        return;
    }

    const fields = integrationRequiredFields(scope, type);
    if (fields.length === 0) {
        container.innerHTML = '<span class="required-field-none">Nema obaveznih provider polja za ovaj tip.</span>';
        return;
    }

    container.innerHTML = fields
        .map((field) => `<span class="required-field-chip">${escapeHtml(field)}</span>`)
        .join('');
}

function applySimpleIntegrationVisibility(scope, type) {
    const normalizedType = String(type ?? '').trim().toLowerCase();
    const knownTypes = ['woocommerce', 'wordpress_rest', 'shopify', 'custom_api'];
    const hasSimpleSetup = integrationSupportsSimpleSetup(normalizedType);
    const needsBaseUrl = integrationRequiresBaseUrl(normalizedType);
    const advancedToggle = document.getElementById(`${scope}-advanced-toggle`);
    const advancedWrap = document.getElementById(`${scope}-advanced-wrap`);

    const simpleCard = document.getElementById(`${scope}-simple-card`);
    if (simpleCard) {
        simpleCard.classList.toggle('hidden', !hasSimpleSetup);
    }

    const baseUrlWrap = document.getElementById(`${scope}-base-url-wrap`);
    if (baseUrlWrap) {
        baseUrlWrap.classList.toggle('hidden', !needsBaseUrl);
    }
    if (advancedToggle) {
        advancedToggle.classList.toggle('hidden', !hasSimpleSetup);
    }
    if (advancedWrap && !hasSimpleSetup) {
        advancedWrap.classList.add('hidden');
    }
    if (advancedToggle) {
        const hidden = !advancedWrap || advancedWrap.classList.contains('hidden');
        advancedToggle.textContent = hidden ? 'Show Advanced Options' : 'Hide Advanced Options';
    }

    knownTypes.forEach((itemType) => {
        const block = document.getElementById(`${scope}-simple-${itemType}`);
        if (!block) {
            return;
        }
        block.classList.toggle('hidden', normalizedType !== itemType);
    });

    applySimpleAuthModeVisibility(scope, normalizedType);
}

function applySimpleAuthModeVisibility(scope, type) {
    const normalizedType = String(type ?? '').trim().toLowerCase();

    const wpMode = scopedIntegrationValue(scope, 'wp-auth-mode') ?? 'none';
    const wpBasic = document.getElementById(`${scope}-wp-basic-fields`);
    const wpBearer = document.getElementById(`${scope}-wp-bearer-fields`);
    if (wpBasic) {
        wpBasic.classList.toggle('hidden', normalizedType !== 'wordpress_rest' || wpMode !== 'basic');
    }
    if (wpBearer) {
        wpBearer.classList.toggle('hidden', normalizedType !== 'wordpress_rest' || wpMode !== 'bearer');
    }

    const customMode = scopedIntegrationValue(scope, 'custom-auth-mode') ?? 'bearer';
    const customBearer = document.getElementById(`${scope}-custom-bearer-fields`);
    const customBasic = document.getElementById(`${scope}-custom-basic-fields`);
    const customApiHeader = document.getElementById(`${scope}-custom-api-header-fields`);
    const customApiQuery = document.getElementById(`${scope}-custom-api-query-fields`);
    if (customBearer) {
        customBearer.classList.toggle('hidden', normalizedType !== 'custom_api' || customMode !== 'bearer');
    }
    if (customBasic) {
        customBasic.classList.toggle('hidden', normalizedType !== 'custom_api' || customMode !== 'basic');
    }
    if (customApiHeader) {
        customApiHeader.classList.toggle('hidden', normalizedType !== 'custom_api' || customMode !== 'api_key_header');
    }
    if (customApiQuery) {
        customApiQuery.classList.toggle('hidden', normalizedType !== 'custom_api' || customMode !== 'api_key_query');
    }

    renderIntegrationRequiredLegend(scope, normalizedType);
}

function applySimpleTemplateDefaults(scope, type, template) {
    const normalizedType = String(type ?? '').trim().toLowerCase();
    const configExample = isPlainObject(template?.configExample) ? template.configExample : {};

    if (normalizedType === 'wordpress_rest') {
        setScopedValue(scope, 'wp-auth-mode', 'basic');
        const resourcePath = String(configExample.resource_path ?? '/wp-json/wp/v2/posts');
        setScopedValueIfEmpty(scope, 'wp-resource-path', resourcePath);
    } else if (normalizedType === 'custom_api') {
        setScopedValue(scope, 'custom-auth-mode', 'bearer');
        const endpoint = String(configExample.products_endpoint ?? '/products');
        setScopedValueIfEmpty(scope, 'custom-products-endpoint', endpoint);
    }

    applySimpleAuthModeVisibility(scope, normalizedType);
}

function setScopedValueIfEmpty(scope, suffix, value) {
    const element = document.getElementById(`${scope}-${suffix}`);
    if (!element) {
        return;
    }

    if (String(element.value ?? '').trim() !== '') {
        return;
    }

    element.value = value;
}

function setScopedValue(scope, suffix, value) {
    const element = document.getElementById(`${scope}-${suffix}`);
    if (!element) {
        return;
    }

    element.value = value;
}

function bindTenants() {
    elements.tenantsLoad?.addEventListener('click', () => {
        withGuard(loadTenants);
    });

    elements.tenantsTableBody?.addEventListener('click', async (event) => {
        const button = event.target.closest('button[data-action]');
        if (!button) {
            return;
        }

        const id = Number(button.dataset.id);
        const action = button.dataset.action;
        const tenant = state.tenants.find((item) => Number(item.id) === id);
        if (!tenant || !action) {
            return;
        }

        await withGuard(async () => {
            if (action === 'switch') {
                await switchTenantContext(tenant.id);
                return;
            }

            if (action === 'edit') {
                openEntityModal({
                    title: `Edit Tenant #${tenant.id}`,
                    fields: tenantEditFields(tenant),
                    onSubmit: async (payload) => {
                        const response = await request(`/api/admin/auth/tenants/${tenant.id}`, { method: 'PUT', body: payload });
                        const updated = response.data ?? null;
                        if (tenant.is_current && updated?.slug) {
                            state.tenantSlug = String(updated.slug);
                            persistSession();
                            renderSessionStatus();
                        }
                        showAlert('Tenant settings sacuvane.', 'ok');
                        await loadTenants();
                    },
                });
                return;
            }

            if (action === 'delete') {
                openConfirmModal(`Delete tenant "${tenant.name}" (${tenant.slug})? Ova akcija je trajna.`, async () => {
                    const response = await request(`/api/admin/auth/tenants/${tenant.id}`, { method: 'DELETE' });
                    const meta = response.meta ?? {};
                    const currentDeleted = Boolean(meta.current_tenant_deleted) || Boolean(tenant.is_current);

                    if (currentDeleted) {
                        clearSession();
                        renderSessionStatus();
                        clearRenderedData();
                        showAlert('Aktivni tenant je obrisan. Prijavite se ponovo.', 'ok');
                        return;
                    }

                    showAlert('Tenant je obrisan.', 'ok');
                    await loadTenants();
                });
            }
        });
    });
}

async function switchTenantContext(tenantId) {
    const response = await request('/api/admin/auth/switch-tenant', {
        method: 'POST',
        body: { tenant_id: tenantId },
    });

    const data = response.data ?? {};
    state.token = data.token ?? null;
    state.tenantSlug = data.tenant?.slug ?? state.tenantSlug;
    state.user = data.user ?? state.user;
    state.role = data.role ?? state.role;
    persistSession();
    renderSessionStatus();

    await bootstrapAuthenticatedState();
    showAlert(`Tenant context prebacen na "${data.tenant?.name ?? data.tenant?.slug ?? tenantId}".`, 'ok');
}

function bindUsers() {
    elements.usersLoad?.addEventListener('click', () => {
        withGuard(loadUsers);
    });

    elements.userCreateForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        await withGuard(async () => {
            const payload = compactPayload({
                name: emptyToUndefined(elements.userName.value),
                email: elements.userEmail.value.trim(),
                role: elements.userRole.value,
                password: emptyToUndefined(elements.userPassword.value),
                password_confirmation: emptyToUndefined(elements.userPasswordConfirmation.value),
                is_system_admin: isSystemAdmin() ? Boolean(elements.userSystemAdmin?.checked) : undefined,
            });

            await request('/api/admin/users', { method: 'POST', body: payload });
            elements.userCreateForm.reset();
            elements.userRole.value = 'support';
            if (elements.userSystemAdmin) {
                elements.userSystemAdmin.checked = false;
            }
            showAlert('User je sacuvan.', 'ok');
            await loadUsers();
        });
    });

    elements.usersTableBody?.addEventListener('click', async (event) => {
        const button = event.target.closest('button[data-action]');
        if (!button) {
            return;
        }

        const id = Number(button.dataset.id);
        const action = button.dataset.action;
        const tenantUser = state.users.find((item) => Number(item.id) === id);
        if (!tenantUser || !action) {
            return;
        }

        await withGuard(async () => {
            if (action === 'reset_password') {
                await request(`/api/admin/users/${tenantUser.id}/password-reset-link`, { method: 'POST' });
                showAlert(`Password reset email poslan za ${tenantUser.email}.`, 'ok');
                return;
            }

            if (action === 'edit') {
                openEntityModal({
                    title: `Edit User #${tenantUser.id}`,
                    fields: tenantUserEditFields(tenantUser),
                    onSubmit: async (payload) => {
                        await request(`/api/admin/users/${tenantUser.id}`, { method: 'PUT', body: payload });
                        await loadUsers();
                    },
                });
                return;
            }

            if (action === 'delete') {
                openConfirmModal(`Remove user "${tenantUser.email}" from tenant?`, async () => {
                    await request(`/api/admin/users/${tenantUser.id}`, { method: 'DELETE' });
                    await loadUsers();
                });
            }
        });
    });
}

function bindWidgets() {
    elements.widgetsLoad?.addEventListener('click', () => {
        withGuard(loadWidgets);
    });

    elements.widgetCreateForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        await withGuard(async () => {
            const payload = compactPayload({
                name: elements.widgetCreateName.value.trim(),
                default_locale: emptyToUndefined(elements.widgetCreateLocale.value),
                allowed_domains_json: parseJsonField(elements.widgetCreateDomains.value, 'Widget domains'),
                theme_json: parseJsonField(elements.widgetCreateTheme.value, 'Widget theme'),
                is_active: elements.widgetCreateActive.checked,
            });
            await request('/api/admin/widgets', { method: 'POST', body: payload });
            elements.widgetCreateForm.reset();
            elements.widgetCreateActive.checked = true;
            showAlert('Widget kreiran.', 'ok');
            await loadWidgets();
        });
    });

    elements.widgetsTableBody?.addEventListener('click', async (event) => {
        const button = event.target.closest('button[data-action]');
        if (!button) {
            return;
        }

        const widgetId = Number(button.dataset.id);
        const action = button.dataset.action;
        const widget = state.widgets.find((item) => Number(item.id) === widgetId);
        if (!widget || !action) {
            return;
        }

        if (action === 'edit') {
            openEntityModal({
                title: `Edit Widget #${widget.id}`,
                fields: widgetEditFields(widget),
                onSubmit: async (payload) => {
                    await request(`/api/admin/widgets/${widget.id}`, { method: 'PUT', body: payload });
                    await loadWidgets();
                },
            });
            return;
        }

        if (action === 'delete') {
            openConfirmModal(`Delete widget "${widget.name}"?`, async () => {
                await request(`/api/admin/widgets/${widget.id}`, { method: 'DELETE' });
                await loadWidgets();
            });
            return;
        }

        if (action === 'copy_public_key') {
            try {
                await navigator.clipboard.writeText(String(widget.public_key ?? ''));
                showAlert('Public key copied.', 'ok');
            } catch {
                showAlert('Clipboard copy nije uspio. Kopirajte kljuc rucno.', 'error');
            }
        }
    });
}

function bindModalInfrastructure() {
    elements.entityModalClose?.addEventListener('click', closeEntityModal);
    elements.entityModal?.addEventListener('click', (event) => {
        if (event.target === elements.entityModal) {
            closeEntityModal();
        }
    });
    elements.entityModalForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (typeof state.modalSubmitHandler !== 'function') {
            return;
        }

        try {
            const payload = collectModalPayload();
            await state.modalSubmitHandler(payload);
            closeEntityModal();
            showAlert('Uspjesno sacuvano.', 'ok');
        } catch (error) {
            showAlert(error.message, 'error');
        }
    });

    elements.confirmCancel?.addEventListener('click', closeConfirmModal);
    elements.confirmModal?.addEventListener('click', (event) => {
        if (event.target === elements.confirmModal) {
            closeConfirmModal();
        }
    });
    elements.confirmAccept?.addEventListener('click', async () => {
        if (typeof state.confirmHandler !== 'function') {
            return;
        }
        try {
            await state.confirmHandler();
            closeConfirmModal();
            showAlert('Delete uspjesan.', 'ok');
        } catch (error) {
            showAlert(error.message, 'error');
        }
    });

    window.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }
        if (!elements.entityModal.classList.contains('hidden')) {
            closeEntityModal();
        }
        if (!elements.confirmModal.classList.contains('hidden')) {
            closeConfirmModal();
        }
    });
}

function bindAuth() {
    elements.loginForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        try {
            const payload = {
                email: elements.loginEmail.value.trim(),
                password: elements.loginPassword.value,
            };

            const response = await request('/api/admin/auth/login', {
                method: 'POST',
                body: payload,
                auth: false,
            });

            const data = response.data ?? {};
            state.token = data.token ?? null;
            state.tenantSlug = data.tenant?.slug ?? state.tenantSlug;
            state.user = data.user ?? null;
            state.role = data.role ?? null;

            if (!state.tenantSlug) {
                throw new Error('Login uspjesan, ali tenant nije dodijeljen ovom korisniku.');
            }

            persistSession();
            renderSessionStatus();
            showAlert('Prijava uspjesna.', 'ok');
            await bootstrapAuthenticatedState();
        } catch (error) {
            showAlert(error.message, 'error');
        }
    });

    elements.forgotPasswordForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        try {
            const payload = {
                email: elements.forgotPasswordEmail.value.trim(),
            };

            await request('/api/admin/auth/password/request', {
                method: 'POST',
                body: payload,
                auth: false,
            });

            elements.forgotPasswordForm.reset();
            showAlert('Ako nalog postoji, reset link je poslan na email adresu.', 'ok');
        } catch (error) {
            showAlert(error.message, 'error');
        }
    });

    const performLogout = async () => {
        try {
            if (state.token) {
                await request('/api/admin/auth/logout', { method: 'POST' });
            }
        } catch (error) {
            showAlert(error.message, 'error');
        } finally {
            clearSession();
            renderSessionStatus();
            clearRenderedData();
            showAlert('Odjavljeni ste.', 'ok');
        }
    };

    elements.logoutButton?.addEventListener('click', performLogout);
    elements.loggedInLogoutButton?.addEventListener('click', performLogout);
}

function bindIntegrations() {
    bindIntegrationAdvancedToggle('integration');

    elements.integrationsLoad?.addEventListener('click', () => {
        withGuard(async () => {
            await loadIntegrations();
            await loadPresetsForFirstIntegration();
        });
    });

    elements.integrationType?.addEventListener('change', () => {
        applyIntegrationTypeGuide(elements.integrationType.value, false);
    });

    document.getElementById('integration-wp-auth-mode')?.addEventListener('change', () => {
        applySimpleAuthModeVisibility('integration', elements.integrationType?.value ?? '');
    });
    document.getElementById('integration-custom-auth-mode')?.addEventListener('change', () => {
        applySimpleAuthModeVisibility('integration', elements.integrationType?.value ?? '');
    });

    elements.integrationApplyTemplate?.addEventListener('click', () => {
        applyIntegrationTypeGuide(elements.integrationType.value, true);
        showAlert('Template je primijenjen za izabrani integration type.', 'ok');
    });

    elements.integrationCreateForm?.addEventListener('submit', async (event) => {
        event.preventDefault();

        await withGuard(async () => {
            const integrationPayload = buildIntegrationPayload();
            const payload = compactPayload({
                type: elements.integrationType.value,
                name: elements.integrationName.value.trim(),
                base_url: integrationPayload.base_url,
                auth_type: integrationPayload.auth_type,
                credentials: integrationPayload.credentials,
                config_json: integrationPayload.config_json,
                mapping_json: integrationPayload.mapping_json,
                sync_frequency: emptyToUndefined(elements.integrationSyncFrequency?.value) ?? 'every_15m',
            });

            await request('/api/admin/integrations', { method: 'POST', body: payload });
            elements.integrationCreateForm.reset();
            applyIntegrationTypeGuide(elements.integrationType.value, false);
            showAlert('Integration snimljen.', 'ok');
            await loadIntegrations();
        });
    });

    elements.integrationsTableBody?.addEventListener('click', async (event) => {
        const button = event.target.closest('button[data-action]');
        if (!button) {
            return;
        }

        await withGuard(async () => {
            const integrationId = button.dataset.id;
            const action = button.dataset.action;
            if (!integrationId || !action) {
                return;
            }

            if (action === 'test') {
                const result = await request(`/api/admin/integrations/${integrationId}/test`, { method: 'POST' });
                const ok = Boolean(result.data?.ok);
                const message = String(result.data?.message ?? (ok ? 'Connection test passed.' : 'Connection test failed.'));
                showAlert(`${ok ? 'Test uspjesan' : 'Test nije uspio'}: ${message}`, ok ? 'ok' : 'error');
                await loadIntegrations();
                return;
            }

            if (action === 'sync_initial' || action === 'sync_delta') {
                const mode = action === 'sync_initial' ? 'initial' : 'delta';
                const syncResponse = await request(`/api/admin/integrations/${integrationId}/sync`, {
                    method: 'POST',
                    body: {
                        mode,
                        validate_connection: true,
                    },
                });
                const importJobId = intOrUndefined(syncResponse?.data?.id);
                if (importJobId === undefined || importJobId <= 0) {
                    showAlert(`Sync pokrenut (${mode}).`, 'ok');
                    await loadImportJobs();
                    await loadIntegrations();
                    return;
                }

                showAlert(`Sync pokrenut (${mode}) [job #${importJobId}].`, 'ok');
                await loadImportJobs();
                await loadIntegrations();

                const completedJob = await waitForImportJobCompletion(importJobId, {
                    timeoutMs: 90000,
                    pollMs: 2000,
                });

                await loadImportJobs();
                await loadIntegrations();
                await loadProducts();

                if (!completedJob) {
                    showAlert(
                        `Sync job #${importJobId} je i dalje pending/processing. Provjerite queue worker/Horizon i Import Jobs tab.`,
                        'error',
                    );
                    return;
                }

                const finalStatus = String(completedJob.status ?? '');
                const success = intOrUndefined(completedJob.success_records) ?? 0;
                const failed = intOrUndefined(completedJob.failed_records) ?? 0;
                const skipped = intOrUndefined(completedJob.skipped_records) ?? 0;
                const summary = String(completedJob.log_summary ?? '').trim();

                if (finalStatus === 'failed') {
                    throw new Error(summary !== '' ? summary : `Sync job #${importJobId} failed.`);
                }

                if (finalStatus === 'completed_with_errors') {
                    showAlert(
                        `Sync zavrsen uz greske (success=${success}, failed=${failed}, skipped=${skipped}). ${summary || ''}`.trim(),
                        'error',
                    );
                    return;
                }

                if (success <= 0) {
                    showAlert(
                        `Sync zavrsen bez novih proizvoda. Provjerite mapping/filtere i Import Jobs detalje. ${summary || ''}`.trim(),
                        'error',
                    );
                    return;
                }

                showAlert(`Sync zavrsen: importovano ${success} proizvoda.`, 'ok');
                return;
            }

            if (action === 'presets') {
                elements.presetIntegrationId.value = integrationId;
                await loadPresets(Number(integrationId));
                return;
            }

            const integration = state.integrations.find((item) => Number(item.id) === Number(integrationId));
            if (!integration) {
                return;
            }

            if (action === 'edit') {
                openEntityModal({
                    title: `Edit Integration #${integration.id}`,
                    fields: integrationEditFields(integration),
                    onSubmit: async (payload) => {
                        await request(`/api/admin/integrations/${integration.id}`, { method: 'PUT', body: payload });
                        await loadIntegrations();
                        await loadPresetsForFirstIntegration();
                    },
                });
                return;
            }

            if (action === 'delete') {
                openConfirmModal(`Delete integration "${integration.name}"?`, async () => {
                    await request(`/api/admin/integrations/${integration.id}`, { method: 'DELETE' });
                    await loadIntegrations();
                    await loadPresetsForFirstIntegration();
                });
            }
        });
    });

    elements.presetCreateForm?.addEventListener('submit', async (event) => {
        event.preventDefault();

        await withGuard(async () => {
            const integrationId = Number(elements.presetIntegrationId.value);
            if (!Number.isFinite(integrationId) || integrationId <= 0) {
                throw new Error('Integration ID nije validan.');
            }

            const payload = {
                name: elements.presetName.value.trim(),
                mapping_json: parseJsonField(elements.presetMapping.value, 'Preset mapping'),
                apply_to_connection: false,
            };

            await request(`/api/admin/integrations/${integrationId}/mapping-presets`, {
                method: 'POST',
                body: payload,
            });

            showAlert('Preset kreiran.', 'ok');
            elements.presetCreateForm.reset();
            elements.presetIntegrationId.value = String(integrationId);
            await loadPresets(integrationId);
        });
    });

    elements.presetsTableBody?.addEventListener('click', async (event) => {
        const button = event.target.closest('button[data-action]');
        if (!button) {
            return;
        }

        await withGuard(async () => {
            const presetId = button.dataset.id;
            const action = button.dataset.action;
            if (!presetId || !action) {
                return;
            }

            if (action === 'apply_preset') {
                await request(`/api/admin/mapping-presets/${presetId}/apply`, { method: 'POST' });
                showAlert('Preset primijenjen na integraciju.', 'ok');
                return;
            }

            const preset = state.presets.find((item) => Number(item.id) === Number(presetId));
            if (!preset) {
                return;
            }

            if (action === 'edit') {
                openEntityModal({
                    title: `Edit Preset #${preset.id}`,
                    fields: presetEditFields(preset),
                    onSubmit: async (payload) => {
                        await request(`/api/admin/mapping-presets/${preset.id}`, { method: 'PUT', body: payload });
                        const selectedIntegrationId = currentPresetIntegrationId();
                        if (selectedIntegrationId !== null) {
                            await loadPresets(selectedIntegrationId);
                        }
                    },
                });
                return;
            }

            if (action === 'delete') {
                openConfirmModal(`Delete mapping preset "${preset.name}"?`, async () => {
                    await request(`/api/admin/mapping-presets/${preset.id}`, { method: 'DELETE' });
                    const selectedIntegrationId = currentPresetIntegrationId();
                    if (selectedIntegrationId !== null) {
                        await loadPresets(selectedIntegrationId);
                    }
                });
            }
        });
    });

    applyIntegrationTypeGuide(elements.integrationType?.value ?? 'woocommerce', false);
}

function integrationTypeTemplate(type) {
    const normalized = String(type ?? '').trim().toLowerCase();

    if (normalized === 'wordpress_rest') {
        return {
            guide: 'WordPress REST: Obavezno unesite Base URL i Resource Path. Zatim izaberite Auth Mode: none / basic / bearer. Basic trazi username + app password, bearer trazi token.',
            baseUrlPlaceholder: 'https://beautyshop.ba',
            authTypePlaceholder: 'basic ili bearer',
            authTypeDefault: 'basic',
            credentialsExample: {
                username: 'wp_api_user',
                password: 'application-password-iz-wordpressa',
            },
            configExample: {
                resource_path: '/wp-json/wp/v2/posts',
            },
        };
    }

    if (normalized === 'woocommerce') {
        return {
            guide: 'WooCommerce: Obavezno unesite Base URL (samo domen), Consumer Key i Consumer Secret. Sistem automatski koristi woo auth.',
            baseUrlPlaceholder: 'https://beautyshop.ba',
            authTypePlaceholder: 'woocommerce_key_secret',
            authTypeDefault: 'woocommerce_key_secret',
            credentialsExample: {
                consumer_key: 'ck_xxxxxxxxx',
                consumer_secret: 'cs_xxxxxxxxx',
            },
            configExample: {},
        };
    }

    if (normalized === 'shopify') {
        return {
            guide: 'Shopify: Obavezno unesite Base URL shop domen i Access Token.',
            baseUrlPlaceholder: 'https://demo.myshopify.com',
            authTypePlaceholder: 'shopify_token',
            authTypeDefault: 'shopify_token',
            credentialsExample: {
                access_token: 'shpat_xxxxxxxxx',
            },
            configExample: {},
        };
    }

    if (normalized === 'custom_api') {
        return {
            guide: 'Custom API: Obavezno unesite Base URL + Products endpoint. Zatim izaberite Auth Mode (bearer/basic/api key/none) i unesite samo trazena polja za taj mode.',
            baseUrlPlaceholder: 'https://api.example.com',
            authTypePlaceholder: 'basic / bearer / api_key_header / api_key_query',
            authTypeDefault: 'bearer',
            credentialsExample: {
                token: 'api_token',
            },
            configExample: {
                products_endpoint: '/products',
                orders: {
                    endpoint: '/orders',
                },
            },
        };
    }

    return {
        guide: 'Za ovaj tip unosite samo osnovne podatke. Advanced overrides koristite samo ako trebate custom JSON ponasanje.',
        baseUrlPlaceholder: 'https://example.com',
        authTypePlaceholder: 'basic / bearer / api_key',
        authTypeDefault: '',
        credentialsExample: {},
        configExample: {},
    };
}

function applyIntegrationTypeGuide(type, applyTemplate = false) {
    const template = integrationTypeTemplate(type);
    applySimpleIntegrationVisibility('integration', type);

    if (elements.integrationQuickGuide) {
        elements.integrationQuickGuide.textContent = template.guide;
    }
    if (elements.integrationBaseUrl) {
        elements.integrationBaseUrl.placeholder = template.baseUrlPlaceholder;
    }
    if (elements.integrationAuthType) {
        elements.integrationAuthType.placeholder = template.authTypePlaceholder;
    }

    if (!applyTemplate) {
        return;
    }

    if (elements.integrationAuthType && String(elements.integrationAuthType.value ?? '').trim() === '') {
        elements.integrationAuthType.value = '';
    }

    applySimpleTemplateDefaults('integration', type, template);

    if (elements.integrationConfig && Object.keys(template.configExample ?? {}).length > 0) {
        if (String(elements.integrationConfig.value ?? '').trim() === '') {
            elements.integrationConfig.value = JSON.stringify(template.configExample, null, 2);
        }
    } else if (elements.integrationConfig) {
        if (String(elements.integrationConfig.value ?? '').trim() === '') {
            elements.integrationConfig.value = '';
        }
    }
}

function applyOnboardingIntegrationGuide(type, applyTemplate = false) {
    const template = integrationTypeTemplate(type);
    applySimpleIntegrationVisibility('onboarding', type);

    if (elements.onboardingIntegrationGuide) {
        elements.onboardingIntegrationGuide.textContent = template.guide;
    }
    if (elements.onboardingIntegrationBaseUrl) {
        elements.onboardingIntegrationBaseUrl.placeholder = template.baseUrlPlaceholder;
    }
    if (elements.onboardingIntegrationAuthType) {
        elements.onboardingIntegrationAuthType.placeholder = template.authTypePlaceholder;
    }

    if (!applyTemplate) {
        return;
    }

    if (elements.onboardingIntegrationAuthType && String(elements.onboardingIntegrationAuthType.value ?? '').trim() === '') {
        elements.onboardingIntegrationAuthType.value = '';
    }

    applySimpleTemplateDefaults('onboarding', type, template);

    if (elements.onboardingIntegrationConfig && Object.keys(template.configExample ?? {}).length > 0) {
        if (String(elements.onboardingIntegrationConfig.value ?? '').trim() === '') {
            elements.onboardingIntegrationConfig.value = JSON.stringify(template.configExample, null, 2);
        }
    } else if (elements.onboardingIntegrationConfig) {
        if (String(elements.onboardingIntegrationConfig.value ?? '').trim() === '') {
            elements.onboardingIntegrationConfig.value = '';
        }
    }
}

function bindProducts() {
    const initialPerPage = intOrUndefined(elements.productsPerPage?.value);
    if (initialPerPage !== undefined && initialPerPage > 0) {
        state.productsPerPage = initialPerPage;
    }

    elements.productFilterForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        state.productsPage = 1;
        await withGuard(loadProducts);
    });

    elements.productsDeleteAll?.addEventListener('click', async () => {
        await withGuard(async () => {
            if (!canBulkDeleteProducts()) {
                throw new Error('Samo owner moze obrisati sve proizvode.');
            }

            openConfirmModal('Obrisati sve proizvode iz ovog tenanta?', async () => {
                const response = await request('/api/admin/products', { method: 'DELETE' });
                const deletedCount = intOrUndefined(response?.data?.deleted_count) ?? 0;
                state.productsPage = 1;
                await loadProducts();
                showAlert(`Obrisano proizvoda: ${deletedCount}.`, 'ok');
            });
        });
    });

    elements.productsPerPage?.addEventListener('change', async () => {
        const perPage = intOrUndefined(elements.productsPerPage.value);
        if (perPage === undefined || perPage <= 0) {
            return;
        }

        state.productsPerPage = perPage;
        state.productsPage = 1;
        await withGuard(loadProducts);
    });

    elements.productsPagePrev?.addEventListener('click', async () => {
        if (state.productsPage <= 1) {
            return;
        }

        state.productsPage -= 1;
        await withGuard(loadProducts);
    });

    elements.productsPageNext?.addEventListener('click', async () => {
        if (state.productsPage >= state.productsLastPage) {
            return;
        }

        state.productsPage += 1;
        await withGuard(loadProducts);
    });

    elements.productCreateForm?.addEventListener('submit', async (event) => {
        event.preventDefault();

        await withGuard(async () => {
            const payload = compactPayload({
                name: elements.productName.value.trim(),
                sku: emptyToUndefined(elements.productSku.value),
                price: Number(elements.productPrice.value),
                category_text: emptyToUndefined(elements.productCategory.value),
                brand_text: emptyToUndefined(elements.productBrand.value),
                product_url: emptyToUndefined(elements.productUrl.value),
                in_stock: elements.productInStock.checked,
                status: 'active',
            });

            await request('/api/admin/products', { method: 'POST', body: payload });
            showAlert('Product snimljen.', 'ok');
            elements.productCreateForm.reset();
            elements.productInStock.checked = true;
            state.productsPage = 1;
            await loadProducts();
        });
    });

    elements.productsTableBody?.addEventListener('click', async (event) => {
        const button = event.target.closest('button[data-action]');
        if (!button) {
            return;
        }

        const id = Number(button.dataset.id);
        const action = button.dataset.action;
        const product = state.products.find((item) => Number(item.id) === id);
        if (!product || !action) {
            return;
        }

        await withGuard(async () => {
            if (action === 'edit') {
                openEntityModal({
                    title: `Edit Product #${product.id}`,
                    fields: productEditFields(product),
                    onSubmit: async (payload) => {
                        await request(`/api/admin/products/${product.id}`, { method: 'PUT', body: payload });
                        await loadProducts();
                    },
                });
                return;
            }

            if (action === 'delete') {
                openConfirmModal(`Delete product "${product.name}"?`, async () => {
                    await request(`/api/admin/products/${product.id}`, { method: 'DELETE' });
                    await loadProducts();
                });
            }
        });
    });
}

function knowledgeTypeOptions(currentValue = null) {
    const options = Object.entries(KNOWLEDGE_TYPE_DEFINITIONS).map(([value, item]) => ({
        value,
        label: `${value} - ${item.label}`,
    }));

    const normalizedCurrent = String(currentValue ?? '').trim();
    const hasCurrent = normalizedCurrent !== '' && options.some((item) => item.value === normalizedCurrent);
    if (!hasCurrent && normalizedCurrent !== '') {
        options.push({
            value: normalizedCurrent,
            label: `${normalizedCurrent} - custom`,
        });
    }

    return options;
}

function updateKnowledgeTypeHelp() {
    if (!elements.knowledgeTypeHelp || !elements.knowledgeType) {
        return;
    }

    const type = String(elements.knowledgeType.value ?? '').trim();
    const definition = KNOWLEDGE_TYPE_DEFINITIONS[type] ?? null;
    elements.knowledgeTypeHelp.textContent = definition?.help
        ?? 'Type definise temu dokumenta koju AI koristi u odgovoru.';
}

function normalizedKnowledgeType(type) {
    const value = String(type ?? '').trim();
    if (value !== '' && Object.prototype.hasOwnProperty.call(KNOWLEDGE_TYPE_DEFINITIONS, value)) {
        return value;
    }

    return 'faq';
}

function ensureActiveKnowledgeGuideType(type) {
    state.activeKnowledgeGuideType = normalizedKnowledgeType(type ?? state.activeKnowledgeGuideType);
}

function renderKnowledgeGuideTabs() {
    if (!elements.knowledgeGuideTabs) {
        return;
    }

    const activeType = normalizedKnowledgeType(state.activeKnowledgeGuideType);
    const entries = Object.entries(KNOWLEDGE_TYPE_DEFINITIONS);
    elements.knowledgeGuideTabs.innerHTML = entries
        .map(([type, item]) => {
            const activeClass = type === activeType ? ' active' : '';
            return `<button class="guide-tab${activeClass}" type="button" data-type="${escapeHtml(type)}" role="tab">${escapeHtml(item.label)}</button>`;
        })
        .join('');
}

function renderKnowledgeGuide() {
    const type = normalizedKnowledgeType(state.activeKnowledgeGuideType);
    const definition = KNOWLEDGE_TYPE_DEFINITIONS[type];
    const guide = KNOWLEDGE_TYPE_GUIDES[type] ?? KNOWLEDGE_TYPE_GUIDES.faq;

    renderKnowledgeGuideTabs();

    if (elements.knowledgeGuideCurrentType) {
        elements.knowledgeGuideCurrentType.textContent = `${type} - ${definition?.label ?? type}`;
    }
    if (elements.knowledgeGuidePurpose) {
        elements.knowledgeGuidePurpose.textContent = guide.purpose ?? '';
    }
    if (elements.knowledgeGuideChecklist) {
        elements.knowledgeGuideChecklist.innerHTML = '';
        for (const item of guide.checklist ?? []) {
            const li = document.createElement('li');
            li.textContent = String(item);
            elements.knowledgeGuideChecklist.appendChild(li);
        }
    }
    if (elements.knowledgeGuideExampleTitle) {
        elements.knowledgeGuideExampleTitle.value = String(guide.exampleTitle ?? '');
    }
    if (elements.knowledgeGuideExampleContent) {
        elements.knowledgeGuideExampleContent.value = String(guide.exampleContent ?? '');
    }
}

function insertKnowledgeGuideExampleIntoForm() {
    const type = normalizedKnowledgeType(state.activeKnowledgeGuideType);
    const guide = KNOWLEDGE_TYPE_GUIDES[type] ?? KNOWLEDGE_TYPE_GUIDES.faq;

    if (elements.knowledgeType) {
        elements.knowledgeType.value = type;
    }

    const currentTitle = String(elements.knowledgeTitle?.value ?? '').trim();
    if (currentTitle === '' && elements.knowledgeTitle) {
        elements.knowledgeTitle.value = String(guide.exampleTitle ?? '');
    }

    if (elements.knowledgeContent) {
        const existing = String(elements.knowledgeContent.value ?? '').trim();
        const exampleContent = String(guide.exampleContent ?? '');
        elements.knowledgeContent.value = existing === ''
            ? exampleContent
            : `${elements.knowledgeContent.value}\n\n${exampleContent}`;
    }

    updateKnowledgeTypeHelp();
    ensureActiveKnowledgeGuideType(type);
    renderKnowledgeGuide();
    showAlert('Primjer je ubacen u Knowledge formu.', 'ok');
}

function knowledgeTypeLabel(type) {
    const key = String(type ?? '').trim();
    const definition = KNOWLEDGE_TYPE_DEFINITIONS[key] ?? null;
    if (!definition) {
        return key || '-';
    }

    return `${key} (${definition.label})`;
}

function applyKnowledgeTemplatePreset() {
    const presetKey = String(elements.knowledgeTemplatePreset?.value ?? '').trim();
    if (presetKey === '') {
        showAlert('Izaberi template preset prije primjene.', 'error');
        return;
    }

    const preset = KNOWLEDGE_TEMPLATE_PRESETS[presetKey];
    if (!preset) {
        showAlert('Nepoznat knowledge template preset.', 'error');
        return;
    }

    if (elements.knowledgeType) {
        elements.knowledgeType.value = preset.type;
    }

    const title = String(elements.knowledgeTitle?.value ?? '').trim();
    if (title === '' && elements.knowledgeTitle) {
        elements.knowledgeTitle.value = preset.title;
    }

    const current = String(elements.knowledgeContent?.value ?? '').trim();
    if (elements.knowledgeContent) {
        elements.knowledgeContent.value = current === ''
            ? preset.content
            : `${elements.knowledgeContent.value}\n\n${preset.content}`;
    }

    ensureActiveKnowledgeGuideType(preset.type);
    updateKnowledgeTypeHelp();
    renderKnowledgeGuide();
    showAlert('Knowledge template je primijenjen.', 'ok');
}

function bindKnowledge() {
    elements.knowledgeLoad?.addEventListener('click', () => {
        withGuard(loadKnowledge);
    });

    elements.knowledgeType?.addEventListener('change', () => {
        ensureActiveKnowledgeGuideType(elements.knowledgeType.value);
        updateKnowledgeTypeHelp();
        renderKnowledgeGuide();
    });

    elements.knowledgeGuideTabs?.addEventListener('click', (event) => {
        const button = event.target.closest('button[data-type]');
        if (!button) {
            return;
        }

        const selectedType = String(button.dataset.type ?? '').trim();
        ensureActiveKnowledgeGuideType(selectedType);
        renderKnowledgeGuide();
    });

    elements.knowledgeGuideUseType?.addEventListener('click', () => {
        const type = normalizedKnowledgeType(state.activeKnowledgeGuideType);
        if (elements.knowledgeType) {
            elements.knowledgeType.value = type;
        }
        updateKnowledgeTypeHelp();
        showAlert('Type je postavljen u Knowledge formi.', 'ok');
    });

    elements.knowledgeGuideInsertExample?.addEventListener('click', () => {
        insertKnowledgeGuideExampleIntoForm();
    });

    elements.knowledgeApplyTemplate?.addEventListener('click', () => {
        applyKnowledgeTemplatePreset();
    });

    elements.knowledgeTextForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        await withGuard(async () => {
            const payload = {
                title: elements.knowledgeTitle.value.trim(),
                type: elements.knowledgeType.value.trim() || 'faq',
                visibility: elements.knowledgeVisibility.value,
                ai_allowed: elements.knowledgeAiAllowed.checked,
                content_raw: elements.knowledgeContent.value.trim(),
            };

            await request('/api/admin/knowledge-documents/text', {
                method: 'POST',
                body: payload,
            });

            showAlert('Knowledge dokument kreiran.', 'ok');
            elements.knowledgeTextForm.reset();
            elements.knowledgeAiAllowed.checked = true;
            if (elements.knowledgeType) {
                elements.knowledgeType.value = 'faq';
            }
            if (elements.knowledgeTemplatePreset) {
                elements.knowledgeTemplatePreset.value = '';
            }
            ensureActiveKnowledgeGuideType('faq');
            updateKnowledgeTypeHelp();
            renderKnowledgeGuide();
            await loadKnowledge();
        });
    });

    elements.knowledgeTableBody?.addEventListener('click', async (event) => {
        const button = event.target.closest('button[data-action]');
        if (!button) {
            return;
        }

        await withGuard(async () => {
            const id = button.dataset.id;
            const action = button.dataset.action;
            if (!id || !action) {
                return;
            }

            if (action === 'reindex') {
                await request(`/api/admin/knowledge-documents/${id}/reindex`, { method: 'POST' });
                showAlert(`Reindex pokrenut za dokument #${id}.`, 'ok');
                return;
            }

            const document = state.knowledgeDocuments.find((item) => Number(item.id) === Number(id));
            if (!document) {
                return;
            }

            if (action === 'edit') {
                openEntityModal({
                    title: `Edit Knowledge #${document.id}`,
                    fields: knowledgeEditFields(document),
                    onSubmit: async (payload) => {
                        await request(`/api/admin/knowledge-documents/${document.id}`, { method: 'PUT', body: payload });
                        await loadKnowledge();
                    },
                });
                return;
            }

            if (action === 'delete') {
                openConfirmModal(`Delete knowledge document "${document.title}"?`, async () => {
                    await request(`/api/admin/knowledge-documents/${document.id}`, { method: 'DELETE' });
                    await loadKnowledge();
                });
            }
        });
    });

    ensureActiveKnowledgeGuideType(elements.knowledgeType?.value ?? 'faq');
    updateKnowledgeTypeHelp();
    renderKnowledgeGuide();
}

function bindConversations() {
    elements.conversationsLoad?.addEventListener('click', () => {
        withGuard(loadConversations);
    });

    elements.conversationsTableBody?.addEventListener('click', async (event) => {
        const button = event.target.closest('button[data-action]');
        if (!button) {
            return;
        }

        await withGuard(async () => {
            const id = button.dataset.id;
            const action = button.dataset.action;
            if (!id || !action) {
                return;
            }

            if (action === 'messages') {
                const response = await request(`/api/admin/conversations/${id}/messages`);
                const messages = Array.isArray(response.data) ? response.data : [];
                renderMessages(messages);
                return;
            }

            const conversation = state.conversations.find((item) => Number(item.id) === Number(id));
            if (!conversation) {
                return;
            }

            if (action === 'edit') {
                openEntityModal({
                    title: `Edit Conversation #${conversation.id}`,
                    fields: conversationEditFields(conversation),
                    onSubmit: async (payload) => {
                        await request(`/api/admin/conversations/${conversation.id}`, { method: 'PUT', body: payload });
                        await loadConversations();
                    },
                });
                return;
            }

            if (action === 'delete') {
                openConfirmModal(`Delete conversation #${conversation.id}?`, async () => {
                    await request(`/api/admin/conversations/${conversation.id}`, { method: 'DELETE' });
                    await loadConversations();
                    renderMessages([]);
                });
            }
        });
    });
}

function bindImports() {
    elements.importsLoad?.addEventListener('click', () => {
        withGuard(loadImportJobs);
    });

    elements.importsTableBody?.addEventListener('click', async (event) => {
        const button = event.target.closest('button[data-action]');
        if (!button) {
            return;
        }

        const id = Number(button.dataset.id);
        const action = button.dataset.action;
        const job = state.importJobs.find((item) => Number(item.id) === id);
        if (!job || !action) {
            return;
        }

        await withGuard(async () => {
            if (action === 'edit') {
                openEntityModal({
                    title: `Edit Import Job #${job.id}`,
                    fields: importJobEditFields(job),
                    onSubmit: async (payload) => {
                        await request(`/api/admin/import-jobs/${job.id}`, { method: 'PUT', body: payload });
                        await loadImportJobs();
                    },
                });
                return;
            }

            if (action === 'delete') {
                openConfirmModal(`Delete import job #${job.id}?`, async () => {
                    await request(`/api/admin/import-jobs/${job.id}`, { method: 'DELETE' });
                    await loadImportJobs();
                });
            }
        });
    });
}

function bindAuditLogs() {
    elements.auditLoad?.addEventListener('click', () => {
        withGuard(loadAuditLogs);
    });

    elements.auditFilterForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        await withGuard(loadAuditLogs);
    });
}

function bindWidgetAbuseLogs() {
    elements.widgetAbuseLoad?.addEventListener('click', () => {
        withGuard(loadWidgetAbuseLogs);
    });

    elements.widgetAbuseFilterForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        await withGuard(loadWidgetAbuseLogs);
    });
}

function bindOrderStatusEvents() {
    elements.orderStatusLoad?.addEventListener('click', () => {
        withGuard(loadOrderStatusEvents);
    });

    elements.orderStatusFilterForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        await withGuard(loadOrderStatusEvents);
    });
}

function bindAiConfig() {
    elements.aiConfigForm?.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (!canReadAiConfig()) {
            showAlert('AI Config je dostupan samo admin/owner ulozi.', 'error');
            return;
        }

        await withGuard(async () => {
            const payload = compactPayload({
                provider: emptyToUndefined(elements.aiProvider.value),
                model_name: emptyToUndefined(elements.aiModelName.value),
                embedding_model: emptyToUndefined(elements.aiEmbeddingModel.value),
                temperature: numberOrUndefined(elements.aiTemperature.value),
                max_output_tokens: intOrUndefined(elements.aiMaxTokens.value),
                max_messages_monthly: intOrUndefined(elements.aiMaxMessagesMonthly?.value),
                max_tokens_daily: intOrUndefined(elements.aiMaxTokensDaily?.value),
                max_tokens_monthly: intOrUndefined(elements.aiMaxTokensMonthly?.value),
                block_on_limit: Boolean(elements.aiBlockOnLimit?.checked ?? true),
                alert_on_limit: Boolean(elements.aiAlertOnLimit?.checked ?? true),
                top_p: numberOrUndefined(elements.aiTopP.value),
                system_prompt_template: emptyToUndefined(elements.aiSystemPrompt.value),
            });

            await request('/api/admin/ai-config', { method: 'PUT', body: payload });
            showAlert('AI config snimljen.', 'ok');
            await loadAiConfig();
        });
    });
}

function bindWidgetLab() {
    elements.widgetSessionForm?.addEventListener('submit', async (event) => {
        event.preventDefault();

        await withGuard(async () => {
            const publicKey = elements.widgetPublicKey.value.trim();
            if (!publicKey) {
                throw new Error('Unesite widget public key.');
            }

            const payload = compactPayload({
                public_key: publicKey,
                source_url: emptyToUndefined(elements.widgetSourceUrl.value),
            });

            const response = await request('/api/admin/widget-lab/session/start', {
                method: 'POST',
                body: payload,
            });

            state.widget.publicKey = publicKey;
            state.widget.conversationId = response.data?.conversation_id ?? null;
            state.widget.sessionId = response.data?.session_id ?? null;
            state.widget.sessionToken = response.data?.widget_session_token ?? null;
            state.widget.visitorUuid = response.data?.visitor_uuid ?? null;

            renderWidgetMessage('system', `Session started (#${state.widget.conversationId ?? '?'})`);
            showAlert('Widget session pokrenut.', 'ok');
        });
    });

    elements.widgetMessageForm?.addEventListener('submit', async (event) => {
        event.preventDefault();

        await withGuard(async () => {
            const publicKey = elements.widgetPublicKey.value.trim();
            const message = elements.widgetMessage.value.trim();
            if (!publicKey) {
                throw new Error('Unesite widget public key.');
            }
            if (!message) {
                throw new Error('Unesite poruku.');
            }

            const payload = compactPayload({
                public_key: publicKey,
                message,
                conversation_id: state.widget.conversationId,
                session_id: state.widget.sessionId,
                widget_session_token: state.widget.sessionToken,
                visitor_uuid: state.widget.visitorUuid,
                source_url: emptyToUndefined(elements.widgetSourceUrl.value),
            });

            renderWidgetMessage('user', message);

            const response = await request('/api/admin/widget-lab/message', {
                method: 'POST',
                body: payload,
            });

            state.widget.conversationId = response.data?.conversation_id ?? state.widget.conversationId;
            state.widget.sessionId = response.data?.session_id ?? state.widget.sessionId;
            state.widget.sessionToken = response.data?.widget_session_token ?? state.widget.sessionToken;

            const answer = response.data?.answer_text ?? 'No answer text returned.';
            renderWidgetMessage('assistant', answer);

            const products = Array.isArray(response.data?.recommended_products)
                ? response.data.recommended_products
                : [];
            if (products.length > 0) {
                const productLines = products
                    .map((product) => `${product.name} (${product.price} ${product.currency})`)
                    .join(' | ');
                renderWidgetMessage('assistant', `Preporuke: ${productLines}`);
            }

            if (response.data?.checkout) {
                const checkout = response.data.checkout;
                const status = String(checkout.status ?? 'collecting_customer');
                const missing = Array.isArray(checkout.missing_fields) ? checkout.missing_fields.join(', ') : '';
                renderWidgetMessage('system', `Checkout status: ${status}${missing ? ` | missing: ${missing}` : ''}`);
            }

            if (response.data?.order?.external_order_id) {
                const order = response.data.order;
                const link = order.checkout_url ? ` | checkout: ${order.checkout_url}` : '';
                renderWidgetMessage('system', `Order placed: #${order.external_order_id}${link}`);
            }

            elements.widgetMessage.value = '';
        });
    });
}

function actionButton(action, id, label, variant = 'btn-ghost') {
    return `<button class="btn ${variant}" data-action="${escapeHtml(String(action))}" data-id="${escapeHtml(String(id))}" type="button">${escapeHtml(label)}</button>`;
}

function renderActionButtons(buttons) {
    return `<div class="table-actions">${buttons.join('')}</div>`;
}

function currentPresetIntegrationId() {
    const integrationId = Number(elements.presetIntegrationId.value);
    if (!Number.isFinite(integrationId) || integrationId <= 0) {
        return Number.isFinite(Number(state.activePresetIntegrationId))
            ? Number(state.activePresetIntegrationId)
            : null;
    }

    return integrationId;
}

function openEntityModal({ title, fields, onSubmit }) {
    state.modalSubmitHandler = onSubmit;
    elements.entityModalTitle.textContent = title;
    elements.entityModalFields.innerHTML = fields.map((field) => renderEntityField(field)).join('');
    elements.entityModal.classList.remove('hidden');
}

function closeEntityModal() {
    state.modalSubmitHandler = null;
    elements.entityModalTitle.textContent = 'Edit';
    elements.entityModalFields.innerHTML = '';
    elements.entityModal.classList.add('hidden');
}

function collectModalPayload() {
    const controls = Array.from(elements.entityModalFields.querySelectorAll('[data-field-key]'));
    const payload = {};

    for (const control of controls) {
        const key = control.dataset.fieldKey;
        const label = control.dataset.fieldLabel ?? key;
        const valueType = control.dataset.valueType ?? 'text';
        const required = control.dataset.required === 'true';
        const nullable = control.dataset.nullable === 'true';

        if (!key) {
            continue;
        }

        if (valueType === 'checkbox') {
            payload[key] = control.checked;
            continue;
        }

        const rawValue = String(control.value ?? '').trim();
        if (rawValue === '') {
            if (required) {
                throw new Error(`${label} je obavezno polje.`);
            }
            if (nullable) {
                payload[key] = null;
            }
            continue;
        }

        if (valueType === 'number') {
            const parsed = Number(rawValue);
            if (!Number.isFinite(parsed)) {
                throw new Error(`${label} mora biti broj.`);
            }
            payload[key] = parsed;
            continue;
        }

        if (valueType === 'integer') {
            const parsed = Number.parseInt(rawValue, 10);
            if (!Number.isFinite(parsed)) {
                throw new Error(`${label} mora biti cijeli broj.`);
            }
            payload[key] = parsed;
            continue;
        }

        if (valueType === 'json') {
            payload[key] = parseJsonField(rawValue, label);
            continue;
        }

        payload[key] = rawValue;
    }

    return payload;
}

function openConfirmModal(message, onConfirm) {
    state.confirmHandler = onConfirm;
    elements.confirmModalMessage.textContent = message;
    elements.confirmModal.classList.remove('hidden');
}

function closeConfirmModal() {
    state.confirmHandler = null;
    elements.confirmModalMessage.textContent = '';
    elements.confirmModal.classList.add('hidden');
}

function renderEntityField(field) {
    const key = escapeHtml(String(field.key));
    const label = escapeHtml(String(field.label ?? field.key));
    const valueType = String(field.type ?? 'text');
    const required = field.required === true ? 'true' : 'false';
    const nullable = field.nullable === true ? 'true' : 'false';

    if (valueType === 'checkbox') {
        const checked = Boolean(field.value) ? 'checked' : '';
        return `
            <label>
                <input
                    type="checkbox"
                    data-field-key="${key}"
                    data-field-label="${label}"
                    data-value-type="checkbox"
                    data-required="${required}"
                    data-nullable="${nullable}"
                    ${checked}
                >
                ${label}
            </label>
        `;
    }

    const value = valueType === 'json'
        ? formatJsonFieldValue(field.value)
        : valueType === 'datetime-local'
            ? toDateTimeLocalValue(field.value)
            : String(field.value ?? '');

    if (valueType === 'textarea' || valueType === 'json') {
        return `
            <label>${label}
                <textarea
                    rows="${field.rows ?? 5}"
                    data-field-key="${key}"
                    data-field-label="${label}"
                    data-value-type="${valueType}"
                    data-required="${required}"
                    data-nullable="${nullable}"
                    ${field.required ? 'required' : ''}
                >${escapeHtml(value)}</textarea>
            </label>
        `;
    }

    if (valueType === 'select') {
        const options = Array.isArray(field.options) ? field.options : [];
        const selectedValue = String(field.value ?? '');
        const optionsHtml = options.map((option) => {
            const optionValue = String(option.value ?? '');
            const selected = optionValue === selectedValue ? 'selected' : '';
            return `<option value="${escapeHtml(optionValue)}" ${selected}>${escapeHtml(String(option.label ?? optionValue))}</option>`;
        }).join('');

        return `
            <label>${label}
                <select
                    data-field-key="${key}"
                    data-field-label="${label}"
                    data-value-type="text"
                    data-required="${required}"
                    data-nullable="${nullable}"
                    ${field.required ? 'required' : ''}
                >
                    ${optionsHtml}
                </select>
            </label>
        `;
    }

    const inputType = valueType === 'integer' ? 'number' : valueType;
    const stepAttr = field.step !== undefined ? `step="${escapeHtml(String(field.step))}"` : '';
    const minAttr = field.min !== undefined ? `min="${escapeHtml(String(field.min))}"` : '';
    const maxAttr = field.max !== undefined ? `max="${escapeHtml(String(field.max))}"` : '';
    const placeholderAttr = field.placeholder ? `placeholder="${escapeHtml(String(field.placeholder))}"` : '';

    return `
        <label>${label}
            <input
                type="${escapeHtml(inputType)}"
                value="${escapeHtml(value)}"
                data-field-key="${key}"
                data-field-label="${label}"
                data-value-type="${escapeHtml(valueType)}"
                data-required="${required}"
                data-nullable="${nullable}"
                ${field.required ? 'required' : ''}
                ${stepAttr}
                ${minAttr}
                ${maxAttr}
                ${placeholderAttr}
            >
        </label>
    `;
}

function formatJsonFieldValue(value) {
    if (value === undefined || value === null || value === '') {
        return '';
    }
    if (typeof value === 'string') {
        return value;
    }

    try {
        return JSON.stringify(value, null, 2);
    } catch {
        return '';
    }
}

function toDateTimeLocalValue(value) {
    if (!value) {
        return '';
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '';
    }

    const pad = (num) => String(num).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function integrationSyncFrequencyOptions(currentValue = 'every_15m') {
    const options = [
        { value: 'every_5m', label: 'every_5m (najazurnije)' },
        { value: 'every_15m', label: 'every_15m (recommended)' },
        { value: 'every_30m', label: 'every_30m' },
        { value: 'hourly', label: 'hourly' },
        { value: 'every_2h', label: 'every_2h' },
        { value: 'every_6h', label: 'every_6h' },
        { value: 'daily', label: 'daily' },
        { value: 'manual', label: 'manual (off)' },
    ];

    const normalizedCurrent = String(currentValue ?? '').trim();
    if (normalizedCurrent !== '' && !options.some((item) => item.value === normalizedCurrent)) {
        options.push({
            value: normalizedCurrent,
            label: `${normalizedCurrent} (custom legacy)`,
        });
    }

    return options;
}

function integrationEditFields(integration) {
    return [
        { key: 'name', label: 'Name', type: 'text', value: integration.name ?? '', required: true },
        {
            key: 'type',
            label: 'Type',
            type: 'select',
            value: integration.type ?? 'manual',
            options: [
                { value: 'woocommerce', label: 'WooCommerce' },
                { value: 'wordpress_rest', label: 'WordPress REST' },
                { value: 'shopify', label: 'Shopify' },
                { value: 'custom_api', label: 'Custom API' },
                { value: 'csv', label: 'CSV' },
                { value: 'manual', label: 'Manual' },
            ],
        },
        { key: 'base_url', label: 'Base URL', type: 'text', value: integration.base_url ?? '', nullable: true },
        { key: 'auth_type', label: 'Auth type', type: 'text', value: integration.auth_type ?? '', nullable: true },
        {
            key: 'sync_frequency',
            label: 'Sync frequency',
            type: 'select',
            value: integration.sync_frequency ?? 'every_15m',
            options: integrationSyncFrequencyOptions(integration.sync_frequency ?? 'every_15m'),
        },
        {
            key: 'credentials',
            label: integration.has_credentials
                ? 'Credentials JSON (secret postoji; ostavi prazno da ostane isto)'
                : 'Credentials JSON',
            type: 'json',
            value: null,
            nullable: true,
        },
        { key: 'config_json', label: 'Config JSON', type: 'json', value: integration.config_json ?? null, nullable: true },
        { key: 'mapping_json', label: 'Mapping JSON', type: 'json', value: integration.mapping_json ?? null, nullable: true },
    ];
}

function tenantEditFields(tenant) {
    return [
        { key: 'name', label: 'Name', type: 'text', value: tenant.name ?? '', required: true },
        { key: 'slug', label: 'Slug', type: 'text', value: tenant.slug ?? '', required: true, placeholder: 'my-tenant' },
        { key: 'domain', label: 'Domain', type: 'text', value: tenant.domain ?? '', nullable: true, placeholder: 'shop.example.com' },
        { key: 'status', label: 'Status', type: 'text', value: tenant.status ?? 'active', required: true },
        { key: 'locale', label: 'Locale', type: 'text', value: tenant.locale ?? 'bs', required: true },
        { key: 'timezone', label: 'Timezone', type: 'text', value: tenant.timezone ?? 'Europe/Sarajevo', required: true },
        { key: 'brand_name', label: 'Brand name', type: 'text', value: tenant.brand_name ?? '', nullable: true },
        { key: 'support_email', label: 'Support email', type: 'email', value: tenant.support_email ?? '', nullable: true },
    ];
}

function tenantUserEditFields(tenantUser) {
    const fields = [
        { key: 'name', label: 'Name', type: 'text', value: tenantUser.name ?? '', required: true },
        { key: 'email', label: 'Email', type: 'email', value: tenantUser.email ?? '', required: true },
        {
            key: 'role',
            label: 'Role',
            type: 'select',
            value: tenantUser.role ?? 'support',
            options: [
                { value: 'support', label: 'support' },
                { value: 'editor', label: 'editor' },
                { value: 'admin', label: 'admin' },
                { value: 'owner', label: 'owner' },
            ],
        },
        { key: 'password', label: 'New password (optional)', type: 'password', value: '', nullable: true },
        { key: 'password_confirmation', label: 'Confirm new password', type: 'password', value: '', nullable: true },
    ];

    if (isSystemAdmin()) {
        fields.splice(3, 0, {
            key: 'is_system_admin',
            label: 'System admin (platform-level)',
            type: 'checkbox',
            value: Boolean(tenantUser.is_system_admin),
        });
    }

    return fields;
}

function presetEditFields(preset) {
    return [
        { key: 'name', label: 'Name', type: 'text', value: preset.name ?? '', required: true },
        { key: 'mapping_json', label: 'Mapping JSON', type: 'json', value: preset.mapping_json ?? {}, required: true },
        { key: 'apply_to_connection', label: 'Apply to integration', type: 'checkbox', value: false },
    ];
}

function productEditFields(product) {
    return [
        { key: 'name', label: 'Name', type: 'text', value: product.name ?? '', required: true },
        { key: 'sku', label: 'SKU', type: 'text', value: product.sku ?? '', nullable: true },
        { key: 'price', label: 'Price', type: 'number', value: product.price ?? 0, required: true, min: 0, step: '0.01' },
        { key: 'sale_price', label: 'Sale price', type: 'number', value: product.sale_price ?? '', nullable: true, min: 0, step: '0.01' },
        { key: 'currency', label: 'Currency', type: 'text', value: product.currency ?? '' },
        { key: 'stock_qty', label: 'Stock qty', type: 'integer', value: product.stock_qty ?? '', nullable: true, min: 0 },
        { key: 'in_stock', label: 'In stock', type: 'checkbox', value: Boolean(product.in_stock) },
        { key: 'category_text', label: 'Category', type: 'text', value: product.category_text ?? '', nullable: true },
        { key: 'brand_text', label: 'Brand', type: 'text', value: product.brand_text ?? '', nullable: true },
        { key: 'product_url', label: 'Product URL', type: 'url', value: product.product_url ?? '', nullable: true },
        { key: 'short_description', label: 'Short description', type: 'textarea', value: product.short_description ?? '', rows: 4, nullable: true },
        {
            key: 'status',
            label: 'Status',
            type: 'select',
            value: product.status ?? 'active',
            options: [
                { value: 'active', label: 'active' },
                { value: 'draft', label: 'draft' },
                { value: 'archived', label: 'archived' },
            ],
        },
    ];
}

function knowledgeEditFields(document) {
    const currentType = String(document.type ?? 'faq');

    return [
        { key: 'title', label: 'Title', type: 'text', value: document.title ?? '', required: true },
        {
            key: 'type',
            label: 'Type',
            type: 'select',
            value: currentType,
            required: true,
            options: knowledgeTypeOptions(currentType),
        },
        {
            key: 'visibility',
            label: 'Visibility',
            type: 'select',
            value: document.visibility ?? 'public',
            options: [
                { value: 'public', label: 'public' },
                { value: 'private', label: 'private' },
                { value: 'disabled', label: 'disabled' },
            ],
        },
        { key: 'status', label: 'Status', type: 'text', value: document.status ?? '' },
        { key: 'language', label: 'Language', type: 'text', value: document.language ?? '' },
        { key: 'ai_allowed', label: 'AI allowed', type: 'checkbox', value: Boolean(document.ai_allowed) },
        { key: 'internal_only', label: 'Internal only', type: 'checkbox', value: Boolean(document.internal_only) },
        { key: 'tags_json', label: 'Tags JSON', type: 'json', value: document.tags_json ?? null, nullable: true },
        { key: 'content_raw', label: 'Content', type: 'textarea', value: document.content_raw ?? '', rows: 7 },
    ];
}

function conversationEditFields(conversation) {
    return [
        { key: 'status', label: 'Status', type: 'text', value: conversation.status ?? 'active', required: true },
        { key: 'lead_captured', label: 'Lead captured', type: 'checkbox', value: Boolean(conversation.lead_captured) },
        { key: 'converted', label: 'Converted', type: 'checkbox', value: Boolean(conversation.converted) },
        { key: 'ended_at', label: 'Ended at', type: 'datetime-local', value: conversation.ended_at ?? '', nullable: true },
    ];
}

function importJobEditFields(job) {
    return [
        { key: 'status', label: 'Status', type: 'text', value: job.status ?? '', required: true },
        { key: 'log_summary', label: 'Log summary', type: 'textarea', value: job.log_summary ?? '', rows: 5, nullable: true },
    ];
}

function widgetEditFields(widget) {
    return [
        { key: 'name', label: 'Name', type: 'text', value: widget.name ?? '', required: true },
        { key: 'default_locale', label: 'Default locale', type: 'text', value: widget.default_locale ?? '' },
        { key: 'allowed_domains_json', label: 'Allowed domains JSON', type: 'json', value: widget.allowed_domains_json ?? null, nullable: true },
        { key: 'theme_json', label: 'Theme JSON', type: 'json', value: widget.theme_json ?? null, nullable: true },
        { key: 'is_active', label: 'Widget active', type: 'checkbox', value: Boolean(widget.is_active) },
    ];
}

async function bootstrapAuthenticatedState() {
    const authResponse = await request('/api/admin/auth/me');
    const authData = authResponse.data ?? {};
    state.user = authData.user ?? state.user;
    state.role = authData.role ?? state.role;
    if (authData.tenant?.slug) {
        state.tenantSlug = String(authData.tenant.slug);
    }
    persistSession();
    renderSessionStatus();

    const loadTasks = [
        loadTenants(),
        loadOverview(),
        loadProducts(),
        loadKnowledge(),
        loadConversations(),
    ];

    if (canManageIntegrations()) {
        loadTasks.push(loadIntegrations());
    }

    if (canReadImportJobs()) {
        loadTasks.push(loadImportJobs());
    }

    if (canManageWidgets()) {
        loadTasks.push(loadWidgets());
    }

    if (canManageUsers()) {
        loadTasks.push(loadUsers());
    }

    if (canReadAuditLogs()) {
        loadTasks.push(loadAuditLogs());
    }

    if (canReadWidgetAbuseLogs()) {
        loadTasks.push(loadWidgetAbuseLogs());
    }

    if (canReadOrderStatusEvents()) {
        loadTasks.push(loadOrderStatusEvents());
    }

    if (canReadAiConfig()) {
        loadTasks.push(loadAiConfig());
    }

    await Promise.all(loadTasks);

    if (canManageIntegrations()) {
        await loadPresetsForFirstIntegration();
    }
}

async function loadTenants() {
    const response = await request('/api/admin/auth/tenants');
    const rows = Array.isArray(response.data) ? response.data : [];
    state.tenants = rows;

    const current = rows.find((row) => Boolean(row.is_current));
    if (current?.slug) {
        state.tenantSlug = String(current.slug);
    }
    if (current?.role) {
        state.role = String(current.role);
    }
    persistSession();
    renderSessionStatus();

    elements.tenantsTableBody.innerHTML = rows.length === 0
        ? '<tr><td colspan="7" class="muted">Nema dostupnih tenant-a za ovog korisnika.</td></tr>'
        : rows.map((row) => {
            const actions = [];
            if (!row.is_current) {
                actions.push(actionButton('switch', row.id, 'Switch'));
            }
            if (row.can_manage) {
                actions.push(actionButton('edit', row.id, 'Edit'));
            }
            if (row.can_delete) {
                actions.push(actionButton('delete', row.id, 'Delete', 'btn-danger'));
            }

            return `
                <tr>
                    <td>${escapeHtml(String(row.id ?? '-'))}</td>
                    <td>${escapeHtml(String(row.name ?? '-'))}</td>
                    <td><code>${escapeHtml(String(row.slug ?? '-'))}</code></td>
                    <td><span class="chip">${escapeHtml(String(row.role ?? '-'))}</span></td>
                    <td>${escapeHtml(String(row.status ?? '-'))}</td>
                    <td>${row.is_current ? 'Da' : 'Ne'}</td>
                    <td>${actions.length > 0 ? renderActionButtons(actions) : '<span class="muted">-</span>'}</td>
                </tr>
            `;
        }).join('');
}

async function loadUsers() {
    if (!canManageUsers()) {
        state.users = [];
        elements.usersTableBody.innerHTML = '<tr><td colspan="7" class="muted">Users su dostupni samo admin/owner ulozi.</td></tr>';
        return;
    }

    const response = await request('/api/admin/users');
    const rows = Array.isArray(response.data) ? response.data : [];
    state.users = rows;

    elements.usersTableBody.innerHTML = rows.length === 0
        ? '<tr><td colspan="7" class="muted">Nema korisnika u tenantu.</td></tr>'
        : rows.map((row) => `
            <tr>
                <td>${escapeHtml(String(row.id ?? '-'))}</td>
                <td>${escapeHtml(String(row.name ?? '-'))}</td>
                <td>${escapeHtml(String(row.email ?? '-'))}</td>
                <td><span class="chip">${escapeHtml(String(row.role ?? '-'))}</span></td>
                <td>${row.is_system_admin ? 'Da' : 'Ne'}</td>
                <td>${row.is_current_user ? 'Da' : 'Ne'}</td>
                <td>
                    ${renderActionButtons([
                        actionButton('reset_password', row.id, 'Reset email'),
                        actionButton('edit', row.id, 'Edit'),
                        actionButton('delete', row.id, 'Remove', 'btn-danger'),
                    ])}
                </td>
            </tr>
        `).join('');
}

async function loadOverview() {
    const response = await request('/api/admin/analytics/overview');
    const data = response.data ?? {};

    elements.statConversations.textContent = String(data.conversations ?? 0);
    elements.statLeads.textContent = String(data.leads ?? 0);
    elements.statEvents.textContent = String(data.events ?? 0);
    elements.statLeadRate.textContent = `${data.lead_capture_rate ?? 0}%`;

    const topEvents = Array.isArray(data.top_events) ? data.top_events : [];
    elements.topEventsList.innerHTML = topEvents.length === 0
        ? '<li class="muted">Nema dogadjaja.</li>'
        : topEvents.map((event) => `<li><strong>${escapeHtml(String(event.event_name ?? '-'))}</strong> - ${escapeHtml(String(event.total ?? 0))}</li>`).join('');
}

async function loadIntegrations() {
    if (!canManageIntegrations()) {
        state.integrations = [];
        elements.integrationsTableBody.innerHTML = '<tr><td colspan="7" class="muted">Integrations su dostupne samo admin/owner ulozi.</td></tr>';
        return;
    }

    const response = await request('/api/admin/integrations');
    const rows = Array.isArray(response.data) ? response.data : [];
    state.integrations = rows;

    elements.integrationsTableBody.innerHTML = rows.length === 0
        ? '<tr><td colspan="7" class="muted">Nema integracija.</td></tr>'
        : rows.map((row) => `
            <tr>
                <td>${escapeHtml(String(row.id))}</td>
                <td>${escapeHtml(String(row.name ?? '-'))}</td>
                <td><span class="chip">${escapeHtml(String(row.type ?? '-'))}</span></td>
                <td>
                    <span class="chip">${escapeHtml(String(row.status ?? '-'))}</span>
                    ${row.last_error ? `<div class="muted">${escapeHtml(String(row.last_error))}</div>` : ''}
                    <div class="muted">Auto sync: ${escapeHtml(String(row.sync_frequency ?? 'manual'))}</div>
                    <div class="muted">Last sync: ${escapeHtml(formatTimestamp(row.last_sync_at))}</div>
                    <div class="muted">
                        Next due: ${escapeHtml(formatTimestamp(row.next_sync_due_at))}
                        ${row.is_sync_overdue ? '<span class="chip">OVERDUE</span>' : ''}
                    </div>
                </td>
                <td>${escapeHtml(String(row.auth_type ?? '-'))}</td>
                <td>${row.has_credentials ? 'set' : '-'}</td>
                <td>
                    ${renderActionButtons([
                        actionButton('test', row.id, 'Test'),
                        actionButton('sync_initial', row.id, 'Initial'),
                        actionButton('sync_delta', row.id, 'Delta'),
                        actionButton('presets', row.id, 'Presets'),
                        actionButton('edit', row.id, 'Edit'),
                        actionButton('delete', row.id, 'Delete', 'btn-danger'),
                    ])}
                </td>
            </tr>
        `).join('');
}

async function loadPresets(integrationId) {
    state.activePresetIntegrationId = integrationId;
    const response = await request(`/api/admin/integrations/${integrationId}/mapping-presets`);
    const rows = Array.isArray(response.data) ? response.data : [];
    state.presets = rows;

    elements.presetsTableBody.innerHTML = rows.length === 0
        ? '<tr><td colspan="4" class="muted">Nema mapping preseta.</td></tr>'
        : rows.map((row) => `
            <tr>
                <td>${escapeHtml(String(row.id))}</td>
                <td>${escapeHtml(String(row.name ?? '-'))}</td>
                <td>${escapeHtml(String(row.integration_connection_id ?? '-'))}</td>
                <td>
                    ${renderActionButtons([
                        actionButton('apply_preset', row.id, 'Apply'),
                        actionButton('edit', row.id, 'Edit'),
                        actionButton('delete', row.id, 'Delete', 'btn-danger'),
                    ])}
                </td>
            </tr>
        `).join('');
}

async function loadPresetsForFirstIntegration() {
    if (!Array.isArray(state.integrations) || state.integrations.length === 0) {
        elements.presetsTableBody.innerHTML = '<tr><td colspan="4" class="muted">Kreirajte integraciju pa preset.</td></tr>';
        state.activePresetIntegrationId = null;
        return;
    }

    const selected = Number(state.activePresetIntegrationId);
    const selectedExists = Number.isFinite(selected)
        && state.integrations.some((item) => Number(item.id) === selected);
    const integrationId = selectedExists
        ? selected
        : Number(state.integrations[0].id);
    if (!Number.isFinite(integrationId)) {
        return;
    }

    elements.presetIntegrationId.value = String(integrationId);
    await loadPresets(integrationId);
}

async function loadProducts() {
    const params = new URLSearchParams();
    const search = elements.productSearch.value.trim();
    const status = elements.productStatus.value.trim();
    if (search) {
        params.set('search', search);
    }
    if (status) {
        params.set('status', status);
    }
    params.set('page', String(Math.max(1, state.productsPage)));
    params.set('per_page', String(Math.max(1, state.productsPerPage)));

    const response = await request(`/api/admin/products${params.toString() ? `?${params.toString()}` : ''}`);
    const rows = Array.isArray(response.data) ? response.data : [];
    const currentPage = intOrUndefined(response.current_page) ?? state.productsPage;
    const lastPage = intOrUndefined(response.last_page) ?? 1;
    const total = intOrUndefined(response.total) ?? rows.length;
    const perPage = intOrUndefined(response.per_page) ?? state.productsPerPage;
    const from = intOrUndefined(response.from) ?? (total > 0 ? ((currentPage - 1) * perPage) + 1 : 0);
    const to = intOrUndefined(response.to) ?? (total > 0 ? Math.min(total, currentPage * perPage) : 0);

    state.productsPage = Math.max(1, currentPage);
    state.productsLastPage = Math.max(1, lastPage);
    state.productsTotal = Math.max(0, total);
    state.productsPerPage = Math.max(1, perPage);
    state.productsFrom = Math.max(0, from);
    state.productsTo = Math.max(0, to);

    if (elements.productsPerPage) {
        const wanted = String(state.productsPerPage);
        const exists = Array.from(elements.productsPerPage.options).some((option) => option.value === wanted);
        if (!exists) {
            const option = document.createElement('option');
            option.value = wanted;
            option.textContent = wanted;
            elements.productsPerPage.append(option);
        }
        elements.productsPerPage.value = wanted;
    }

    if (state.productsPage > state.productsLastPage) {
        state.productsPage = state.productsLastPage;
        await loadProducts();
        return;
    }

    state.products = rows;

    elements.productsTableBody.innerHTML = rows.length === 0
        ? '<tr><td colspan="6" class="muted">Nema proizvoda.</td></tr>'
        : rows.map((row) => `
            <tr>
                <td>${escapeHtml(String(row.id))}</td>
                <td>${escapeHtml(String(row.name ?? '-'))}</td>
                <td>${escapeHtml(String(row.price ?? '-'))} ${escapeHtml(String(row.currency ?? ''))}</td>
                <td>${row.in_stock ? 'Da' : 'Ne'}</td>
                <td>${escapeHtml(String(row.category_text ?? '-'))}</td>
                <td>
                    ${renderActionButtons([
                        actionButton('edit', row.id, 'Edit'),
                        actionButton('delete', row.id, 'Delete', 'btn-danger'),
                    ])}
                </td>
            </tr>
        `).join('');

    renderProductsPagination();
}

function renderProductsPagination() {
    const currentPage = Math.max(1, state.productsPage);
    const lastPage = Math.max(1, state.productsLastPage);

    if (elements.productsPageInfo) {
        elements.productsPageInfo.textContent = `Page ${currentPage} / ${lastPage}`;
    }
    if (elements.productsTotalInfo) {
        if (state.productsTotal <= 0) {
            elements.productsTotalInfo.textContent = 'Total: 0';
        } else {
            elements.productsTotalInfo.textContent = `Total: ${state.productsTotal} (showing ${state.productsFrom}-${state.productsTo})`;
        }
    }
    if (elements.productsPagePrev) {
        elements.productsPagePrev.disabled = currentPage <= 1;
    }
    if (elements.productsPageNext) {
        elements.productsPageNext.disabled = currentPage >= lastPage;
    }
}

async function loadKnowledge() {
    const response = await request('/api/admin/knowledge-documents');
    const rows = Array.isArray(response.data) ? response.data : [];
    state.knowledgeDocuments = rows;

    elements.knowledgeTableBody.innerHTML = rows.length === 0
        ? '<tr><td colspan="5" class="muted">Nema knowledge dokumenata.</td></tr>'
        : rows.map((row) => `
            <tr>
                <td>${escapeHtml(String(row.id))}</td>
                <td>${escapeHtml(String(row.title ?? '-'))}</td>
                <td>${escapeHtml(String(row.status ?? '-'))}</td>
                <td>${escapeHtml(knowledgeTypeLabel(row.type))}</td>
                <td>
                    ${renderActionButtons([
                        actionButton('reindex', row.id, 'Reindex'),
                        actionButton('edit', row.id, 'Edit'),
                        actionButton('delete', row.id, 'Delete', 'btn-danger'),
                    ])}
                </td>
            </tr>
        `).join('');
}

async function loadConversations() {
    const response = await request('/api/admin/conversations');
    const rows = Array.isArray(response.data) ? response.data : [];
    state.conversations = rows;

    elements.conversationsTableBody.innerHTML = rows.length === 0
        ? '<tr><td colspan="5" class="muted">Nema konverzacija.</td></tr>'
        : rows.map((row) => `
            <tr>
                <td>${escapeHtml(String(row.id))}</td>
                <td>${escapeHtml(String(row.status ?? '-'))}</td>
                <td>
                    ${row.latest_order_status
                        ? `<span class="chip">${escapeHtml(String(row.latest_order_status))}</span>${row.latest_order_id ? ` #${escapeHtml(String(row.latest_order_id))}` : ''}`
                        : '<span class="muted">-</span>'}
                </td>
                <td>${escapeHtml(String(row.session_id ?? '-'))}</td>
                <td>
                    ${renderActionButtons([
                        actionButton('messages', row.id, 'Messages'),
                        actionButton('edit', row.id, 'Edit'),
                        actionButton('delete', row.id, 'Delete', 'btn-danger'),
                    ])}
                </td>
            </tr>
        `).join('');
}

function isImportJobTerminalStatus(status) {
    const normalized = String(status ?? '').trim().toLowerCase();
    return ['completed', 'completed_with_errors', 'failed', 'cancelled'].includes(normalized);
}

async function waitForImportJobCompletion(importJobId, options = {}) {
    const timeoutMs = Number.isFinite(Number(options.timeoutMs)) ? Number(options.timeoutMs) : 90000;
    const pollMs = Number.isFinite(Number(options.pollMs)) ? Number(options.pollMs) : 2000;
    const startedAt = Date.now();

    while ((Date.now() - startedAt) < timeoutMs) {
        const response = await request('/api/admin/import-jobs');
        const jobs = Array.isArray(response.data) ? response.data : [];
        const job = jobs.find((item) => Number(item?.id) === Number(importJobId)) ?? null;
        if (!job || !job.status) {
            return null;
        }

        if (isImportJobTerminalStatus(job.status)) {
            return job;
        }

        await delay(pollMs);
    }

    return null;
}

async function loadImportJobs() {
    if (!canReadImportJobs()) {
        state.importJobs = [];
        elements.importsTableBody.innerHTML = '<tr><td colspan="5" class="muted">Import Jobs su dostupni editor/admin/owner ulozi.</td></tr>';
        return;
    }

    const response = await request('/api/admin/import-jobs');
    const rows = Array.isArray(response.data) ? response.data : [];
    state.importJobs = rows;

    elements.importsTableBody.innerHTML = rows.length === 0
        ? '<tr><td colspan="5" class="muted">Nema import poslova.</td></tr>'
        : rows.map((row) => `
            <tr>
                <td>${escapeHtml(String(row.id))}</td>
                <td>${escapeHtml(String(row.job_type ?? '-'))}</td>
                <td>${escapeHtml(String(row.status ?? '-'))}</td>
                <td>${escapeHtml(String(row.log_summary ?? '-'))}</td>
                <td>
                    ${renderActionButtons([
                        actionButton('edit', row.id, 'Edit'),
                        actionButton('delete', row.id, 'Delete', 'btn-danger'),
                    ])}
                </td>
            </tr>
        `).join('');
}

async function loadAuditLogs() {
    if (!canReadAuditLogs()) {
        state.auditLogs = [];
        elements.auditTableBody.innerHTML = '<tr><td colspan="8" class="muted">Audit logs su dostupni samo admin/owner ulozi.</td></tr>';
        return;
    }

    const params = new URLSearchParams();
    const action = elements.auditFilterAction.value.trim();
    const entityType = elements.auditFilterEntity.value.trim();
    const actorUserRaw = elements.auditFilterUser.value.trim();

    if (action) {
        params.set('action', action);
    }
    if (entityType) {
        params.set('entity_type', entityType);
    }
    if (actorUserRaw) {
        const actorUserId = intOrUndefined(actorUserRaw);
        if (actorUserId === undefined || actorUserId <= 0) {
            throw new Error('Actor user ID mora biti validan broj.');
        }
        params.set('actor_user_id', String(actorUserId));
    }

    const query = params.toString();
    const response = await request(`/api/admin/audit-logs${query ? `?${query}` : ''}`);
    const rows = Array.isArray(response.data) ? response.data : [];
    state.auditLogs = rows;

    elements.auditTableBody.innerHTML = rows.length === 0
        ? '<tr><td colspan="8" class="muted">Nema audit log zapisa.</td></tr>'
        : rows.map((row) => {
            const actorName = row.actor?.name ?? row.actor?.email ?? (row.actor_user_id ? `User #${row.actor_user_id}` : 'system');
            const entityId = row.entity_id ?? '-';
            const actorRole = row.actor_role ?? '-';
            const requestPath = row.request_path ?? '-';
            const createdAt = formatTimestamp(row.created_at);

            return `
                <tr>
                    <td>${escapeHtml(String(row.id ?? '-'))}</td>
                    <td><span class="chip">${escapeHtml(String(row.action ?? '-'))}</span></td>
                    <td>${escapeHtml(String(row.entity_type ?? '-'))}</td>
                    <td>${escapeHtml(String(entityId))}</td>
                    <td>${escapeHtml(String(actorName))}</td>
                    <td>${escapeHtml(String(actorRole))}</td>
                    <td><code>${escapeHtml(String(requestPath))}</code></td>
                    <td>${escapeHtml(createdAt)}</td>
                </tr>
            `;
        }).join('');
}

async function loadWidgetAbuseLogs() {
    if (!canReadWidgetAbuseLogs()) {
        state.widgetAbuseLogs = [];
        if (elements.widgetAbuseTableBody) {
            elements.widgetAbuseTableBody.innerHTML = '<tr><td colspan="7" class="muted">Widget abuse logs su dostupni samo admin/owner ulozi.</td></tr>';
        }
        return;
    }

    const params = new URLSearchParams();
    const reason = elements.widgetAbuseFilterReason?.value?.trim() ?? '';
    const ip = elements.widgetAbuseFilterIp?.value?.trim() ?? '';
    const publicKey = elements.widgetAbuseFilterPublicKey?.value?.trim() ?? '';

    if (reason) {
        params.set('reason', reason);
    }
    if (ip) {
        params.set('ip', ip);
    }
    if (publicKey) {
        params.set('public_key', publicKey);
    }

    const query = params.toString();
    const response = await request(`/api/admin/widget-abuse-logs${query ? `?${query}` : ''}`);
    const rows = Array.isArray(response.data) ? response.data : [];
    state.widgetAbuseLogs = rows;

    if (!elements.widgetAbuseTableBody) {
        return;
    }

    elements.widgetAbuseTableBody.innerHTML = rows.length === 0
        ? '<tr><td colspan="7" class="muted">Nema widget abuse log zapisa.</td></tr>'
        : rows.map((row) => {
            const reasonLabel = String(row.reason ?? '-');
            const publicKeyLabel = row.public_key ?? row.widget?.public_key ?? '-';
            const ipLabel = row.ip_address ?? '-';
            const originLabel = row.origin ?? row.referer ?? '-';
            const routeLabel = `${row.http_method ?? 'GET'} ${row.route ?? '-'}`;
            const atLabel = formatTimestamp(row.created_at);

            return `
                <tr>
                    <td>${escapeHtml(String(row.id ?? '-'))}</td>
                    <td><span class="chip">${escapeHtml(reasonLabel)}</span></td>
                    <td><code>${escapeHtml(String(publicKeyLabel))}</code></td>
                    <td>${escapeHtml(String(ipLabel))}</td>
                    <td>${escapeHtml(String(originLabel))}</td>
                    <td><code>${escapeHtml(routeLabel)}</code></td>
                    <td>${escapeHtml(atLabel)}</td>
                </tr>
            `;
        }).join('');
}

async function loadOrderStatusEvents() {
    if (!canReadOrderStatusEvents()) {
        state.orderStatusEvents = [];
        if (elements.orderStatusTableBody) {
            elements.orderStatusTableBody.innerHTML = '<tr><td colspan="8" class="muted">Order status events su dostupni samo admin/owner ulozi.</td></tr>';
        }
        return;
    }

    const params = new URLSearchParams();
    const status = elements.orderStatusFilterStatus?.value?.trim() ?? '';
    const provider = elements.orderStatusFilterProvider?.value?.trim() ?? '';
    const orderId = elements.orderStatusFilterOrderId?.value?.trim() ?? '';

    if (status) {
        params.set('status', status);
    }
    if (provider) {
        params.set('provider', provider);
    }
    if (orderId) {
        params.set('order_id', orderId);
    }

    const query = params.toString();
    const response = await request(`/api/admin/order-status-events${query ? `?${query}` : ''}`);
    const rows = Array.isArray(response.data) ? response.data : [];
    state.orderStatusEvents = rows;

    if (!elements.orderStatusTableBody) {
        return;
    }

    elements.orderStatusTableBody.innerHTML = rows.length === 0
        ? '<tr><td colspan="8" class="muted">Nema order status event zapisa.</td></tr>'
        : rows.map((row) => {
            const normalizedStatus = String(row.normalized_status ?? '-');
            const providerLabel = row.integration_connection?.type ?? row.integration_connection?.name ?? '-';
            const orderIdLabel = row.external_order_id ?? '-';
            const messageText = row.message_text ?? '-';
            const trackingUrl = row.tracking_url ?? null;
            const conversationId = row.conversation_id ?? row.conversation?.id ?? '-';
            const occurredAt = formatTimestamp(row.occurred_at ?? row.created_at);

            return `
                <tr>
                    <td>${escapeHtml(String(row.id ?? '-'))}</td>
                    <td><span class="chip">${escapeHtml(normalizedStatus)}</span></td>
                    <td>${escapeHtml(String(providerLabel))}</td>
                    <td>${escapeHtml(String(orderIdLabel))}</td>
                    <td>${escapeHtml(String(messageText))}</td>
                    <td>${trackingUrl ? `<a href="${escapeHtml(String(trackingUrl))}" target="_blank" rel="noopener noreferrer">Open</a>` : '<span class="muted">-</span>'}</td>
                    <td>${escapeHtml(String(conversationId))}</td>
                    <td>${escapeHtml(occurredAt)}</td>
                </tr>
            `;
        }).join('');
}

async function loadWidgets() {
    if (!canManageWidgets()) {
        state.widgets = [];
        elements.widgetsTableBody.innerHTML = '<tr><td colspan="5" class="muted">Widgets su dostupni samo admin/owner ulozi.</td></tr>';
        return;
    }

    const response = await request('/api/admin/widgets');
    const rows = Array.isArray(response.data) ? response.data : [];
    state.widgets = rows;

    elements.widgetsTableBody.innerHTML = rows.length === 0
        ? '<tr><td colspan="5" class="muted">Nema widgeta.</td></tr>'
        : rows.map((row) => `
            <tr>
                <td>${escapeHtml(String(row.id))}</td>
                <td>${escapeHtml(String(row.name ?? '-'))}</td>
                <td><code>${escapeHtml(String(row.public_key ?? '-'))}</code></td>
                <td>${row.is_active ? 'Da' : 'Ne'}</td>
                <td>
                    ${renderActionButtons([
                        actionButton('copy_public_key', row.id, 'Copy key'),
                        actionButton('edit', row.id, 'Edit'),
                        actionButton('delete', row.id, 'Delete', 'btn-danger'),
                    ])}
                </td>
            </tr>
        `).join('');
}

async function loadAiConfig() {
    if (!canReadAiConfig()) {
        return;
    }

    const response = await request('/api/admin/ai-config');
    const config = response.data ?? {};
    const meta = response.meta ?? {};

    elements.aiProvider.value = String(config.provider ?? 'openai');
    elements.aiModelName.value = String(config.model_name ?? 'gpt-5-mini');
    elements.aiEmbeddingModel.value = String(config.embedding_model ?? 'text-embedding-3-small');
    elements.aiTemperature.value = String(config.temperature ?? 0.3);
    elements.aiMaxTokens.value = String(config.max_output_tokens ?? 350);
    if (elements.aiMaxMessagesMonthly) {
        elements.aiMaxMessagesMonthly.value = config.max_messages_monthly == null ? '' : String(config.max_messages_monthly);
    }
    if (elements.aiMaxTokensDaily) {
        elements.aiMaxTokensDaily.value = config.max_tokens_daily == null ? '' : String(config.max_tokens_daily);
    }
    if (elements.aiMaxTokensMonthly) {
        elements.aiMaxTokensMonthly.value = config.max_tokens_monthly == null ? '' : String(config.max_tokens_monthly);
    }
    if (elements.aiBlockOnLimit) {
        elements.aiBlockOnLimit.checked = config.block_on_limit !== false;
    }
    if (elements.aiAlertOnLimit) {
        elements.aiAlertOnLimit.checked = config.alert_on_limit !== false;
    }
    elements.aiTopP.value = String(config.top_p ?? 1);
    elements.aiSystemPrompt.value = String(config.system_prompt_template ?? '');
    renderAiUsageSummary(meta);
}

function renderAiUsageSummary(meta) {
    if (!elements.aiUsageSummary) {
        return;
    }

    const usage = meta.usage ?? {};
    const limits = meta.limits ?? {};
    const exceeded = Array.isArray(meta.exceeded) ? meta.exceeded : [];

    const messagesValue = Number(usage.messages_monthly ?? 0);
    const messagesLimit = limits.max_messages_monthly == null ? 'plan/default' : String(limits.max_messages_monthly);
    const tokensDailyValue = Number(usage.tokens_daily ?? 0);
    const tokensDailyLimit = limits.max_tokens_daily == null ? 'unlimited' : String(limits.max_tokens_daily);
    const tokensMonthlyValue = Number(usage.tokens_monthly ?? 0);
    const tokensMonthlyLimit = limits.max_tokens_monthly == null ? 'unlimited' : String(limits.max_tokens_monthly);
    const blockOnLimit = limits.block_on_limit === false ? 'off' : 'on';

    const exceededText = exceeded.length === 0
        ? 'none'
        : exceeded
            .map((entry) => String(entry.label ?? entry.type ?? 'limit'))
            .join(', ');

    elements.aiUsageSummary.textContent = `Usage: messages(month) ${messagesValue}/${messagesLimit} | tokens(day) ${tokensDailyValue}/${tokensDailyLimit} | tokens(month) ${tokensMonthlyValue}/${tokensMonthlyLimit} | block=${blockOnLimit} | exceeded=${exceededText}`;
}

function renderMessages(messages) {
    elements.conversationMessages.innerHTML = messages.length === 0
        ? '<li class="muted">Nema poruka za ovu konverzaciju.</li>'
        : messages.map((message) => `
            <li>
                <strong>${escapeHtml(String(message.role ?? 'unknown'))}</strong>
                <span class="muted">${escapeHtml(formatTimestamp(message.created_at))}</span>
                <p>${escapeHtml(String(message.message_text ?? ''))}</p>
            </li>
        `).join('');
}

function renderWidgetMessage(role, text) {
    const roleLabel = role === 'assistant' ? 'AI' : role === 'user' ? 'User' : 'System';
    const li = document.createElement('li');
    li.innerHTML = `<strong>${escapeHtml(roleLabel)}:</strong> ${escapeHtml(text)}`;
    elements.widgetChatLog.prepend(li);
}

function clearRenderedData() {
    elements.statConversations.textContent = '-';
    elements.statLeads.textContent = '-';
    elements.statEvents.textContent = '-';
    elements.statLeadRate.textContent = '-';
    elements.topEventsList.innerHTML = '';
    elements.tenantsTableBody.innerHTML = '';
    elements.usersTableBody.innerHTML = '';
    elements.integrationsTableBody.innerHTML = '';
    elements.presetsTableBody.innerHTML = '';
    elements.productsTableBody.innerHTML = '';
    if (elements.productsPageInfo) {
        elements.productsPageInfo.textContent = 'Page 1 / 1';
    }
    if (elements.productsTotalInfo) {
        elements.productsTotalInfo.textContent = 'Total: 0';
    }
    if (elements.productsPagePrev) {
        elements.productsPagePrev.disabled = true;
    }
    if (elements.productsPageNext) {
        elements.productsPageNext.disabled = true;
    }
    if (elements.productsPerPage) {
        elements.productsPerPage.value = '25';
    }
    elements.knowledgeTableBody.innerHTML = '';
    elements.conversationsTableBody.innerHTML = '';
    elements.conversationMessages.innerHTML = '';
    elements.importsTableBody.innerHTML = '';
    elements.auditTableBody.innerHTML = '';
    if (elements.widgetAbuseTableBody) {
        elements.widgetAbuseTableBody.innerHTML = '';
    }
    if (elements.orderStatusTableBody) {
        elements.orderStatusTableBody.innerHTML = '';
    }
    elements.widgetsTableBody.innerHTML = '';
    elements.widgetChatLog.innerHTML = '';
    elements.auditFilterForm?.reset();
    elements.widgetAbuseFilterForm?.reset();
    elements.orderStatusFilterForm?.reset();
    elements.userCreateForm?.reset();
    if (elements.aiUsageSummary) {
        elements.aiUsageSummary.textContent = 'Usage summary: -';
    }
    if (elements.userRole) {
        elements.userRole.value = 'support';
    }

    state.users = [];
    state.integrations = [];
    state.presets = [];
    state.products = [];
    state.productsPage = 1;
    state.productsPerPage = 25;
    state.productsLastPage = 1;
    state.productsTotal = 0;
    state.productsFrom = 0;
    state.productsTo = 0;
    state.activeKnowledgeGuideType = 'faq';
    state.knowledgeDocuments = [];
    state.conversations = [];
    state.importJobs = [];
    state.tenants = [];
    state.auditLogs = [];
    state.widgetAbuseLogs = [];
    state.orderStatusEvents = [];
    state.widgets = [];
    state.widget.publicKey = '';
    state.widget.conversationId = null;
    state.widget.sessionId = null;
    state.widget.sessionToken = null;
    state.widget.visitorUuid = null;
    state.activePresetIntegrationId = null;
}

function restoreSession() {
    try {
        const raw = localStorage.getItem(SESSION_KEY);
        if (!raw) {
            return;
        }
        const parsed = JSON.parse(raw);
        state.token = parsed.token ?? null;
        state.tenantSlug = parsed.tenantSlug ?? null;
        state.role = parsed.role ?? null;
        state.user = parsed.user ?? null;
    } catch {
        clearSession();
    }
}

function persistSession() {
    const payload = {
        token: state.token,
        tenantSlug: state.tenantSlug,
        role: state.role,
        user: state.user,
    };
    localStorage.setItem(SESSION_KEY, JSON.stringify(payload));
}

function clearSession() {
    state.token = null;
    state.tenantSlug = null;
    state.role = null;
    state.user = null;
    localStorage.removeItem(SESSION_KEY);
}

function renderSessionStatus() {
    updateAuthSurfaceVisibility();
    updateSystemAdminUserControlsVisibility();
    updateProductsDangerControlsVisibility();

    if (!state.token || !state.tenantSlug) {
        elements.sessionStatus.textContent = 'Niste prijavljeni.';
        if (elements.loggedInName) elements.loggedInName.textContent = '-';
        if (elements.loggedInEmail) elements.loggedInEmail.textContent = '-';
        if (elements.loggedInTenant) elements.loggedInTenant.textContent = '-';
        if (elements.loggedInRole) elements.loggedInRole.textContent = '-';
        applyRoleBasedNavigationVisibility();
        return;
    }

    const name = state.user?.name ?? state.user?.email ?? 'admin';
    const email = state.user?.email ?? '-';
    const role = state.role ?? 'support';
    elements.sessionStatus.textContent = `${name} | tenant: ${state.tenantSlug} | role: ${role}`;
    if (elements.loggedInName) elements.loggedInName.textContent = name;
    if (elements.loggedInEmail) elements.loggedInEmail.textContent = email;
    if (elements.loggedInTenant) elements.loggedInTenant.textContent = state.tenantSlug;
    if (elements.loggedInRole) elements.loggedInRole.textContent = role;
    applyRoleBasedNavigationVisibility();
}

async function withGuard(task) {
    try {
        ensureAuthenticated();
        await task();
    } catch (error) {
        showAlert(error.message || 'Neuspjesna operacija.', 'error');
    }
}

function ensureAuthenticated() {
    if (!state.token || !state.tenantSlug) {
        throw new Error('Prvo se prijavite (email, password).');
    }
}

async function request(path, options = {}) {
    const {
        method = 'GET',
        body = undefined,
        auth = true,
    } = options;

    const headers = {
        Accept: 'application/json',
    };

    if (auth) {
        if (!state.token || !state.tenantSlug) {
            throw new Error('Niste prijavljeni.');
        }
        headers.Authorization = `Bearer ${state.token}`;
        headers['X-Tenant-Slug'] = state.tenantSlug;
    }

    if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
    }

    const response = await fetch(path, {
        method,
        headers,
        body: body !== undefined ? JSON.stringify(body) : undefined,
    });

    const text = await response.text();
    const data = text !== '' ? safeJsonParse(text) : {};

    if (!response.ok) {
        throw new Error(extractApiError(data, response.status));
    }

    return data;
}

function safeJsonParse(text) {
    try {
        return JSON.parse(text);
    } catch {
        return {};
    }
}

function extractApiError(data, statusCode) {
    if (typeof data?.message === 'string') {
        if (data.errors && typeof data.errors === 'object') {
            const details = Object.values(data.errors).flat().join(' ');
            return `${data.message} ${details}`.trim();
        }
        return data.message;
    }

    return `Request failed with status ${statusCode}.`;
}

function parseJsonField(value, label) {
    const trimmed = value.trim();
    if (trimmed === '') {
        return undefined;
    }

    try {
        return JSON.parse(trimmed);
    } catch {
        throw new Error(`${label} mora biti validan JSON.`);
    }
}

function compactPayload(payload) {
    return Object.fromEntries(Object.entries(payload).filter(([, value]) => value !== undefined));
}

function emptyToUndefined(value) {
    const trimmed = String(value ?? '').trim();
    return trimmed === '' ? undefined : trimmed;
}

function numberOrUndefined(value) {
    const raw = String(value ?? '').trim();
    if (raw === '') {
        return undefined;
    }
    const number = Number(raw);
    if (!Number.isFinite(number)) {
        return undefined;
    }
    return number;
}

function intOrUndefined(value) {
    const raw = String(value ?? '').trim();
    if (raw === '') {
        return undefined;
    }
    const number = Number.parseInt(raw, 10);
    if (!Number.isFinite(number)) {
        return undefined;
    }
    return number;
}

function delay(ms) {
    return new Promise((resolve) => {
        window.setTimeout(resolve, Math.max(0, Number(ms) || 0));
    });
}

function roleRank(role) {
    const normalized = String(role ?? '').toLowerCase();
    const rankMap = {
        support: 10,
        editor: 20,
        admin: 30,
        owner: 40,
    };

    return rankMap[normalized] ?? 0;
}

function hasRoleAtLeast(minRole) {
    return roleRank(state.role) >= roleRank(minRole);
}

function isSystemAdmin() {
    return Boolean(state.user?.is_system_admin);
}

function canManageUsers() {
    return hasRoleAtLeast('admin');
}

function updateSystemAdminUserControlsVisibility() {
    if (!elements.userSystemAdminWrap) {
        return;
    }

    const visible = isSystemAdmin() && canManageUsers();
    elements.userSystemAdminWrap.classList.toggle('hidden', !visible);

    if (!visible && elements.userSystemAdmin) {
        elements.userSystemAdmin.checked = false;
    }
}

function canManageIntegrations() {
    return hasRoleAtLeast('admin');
}

function canManageWidgets() {
    return hasRoleAtLeast('admin');
}

function canReadImportJobs() {
    return hasRoleAtLeast('editor');
}

function canReadAuditLogs() {
    return hasRoleAtLeast('admin');
}

function canReadWidgetAbuseLogs() {
    return hasRoleAtLeast('admin');
}

function canReadOrderStatusEvents() {
    return hasRoleAtLeast('admin');
}

function canReadAiConfig() {
    return hasRoleAtLeast('admin');
}

function canBulkDeleteProducts() {
    return hasRoleAtLeast('owner');
}

function updateProductsDangerControlsVisibility() {
    if (!elements.productsDeleteAll) {
        return;
    }

    const visible = Boolean(state.token && state.tenantSlug) && canBulkDeleteProducts();
    elements.productsDeleteAll.classList.toggle('hidden', !visible);
}

function canAccessView(view) {
    const viewName = String(view ?? '').trim();

    if (viewName === '' || !state.token || !state.tenantSlug) {
        return false;
    }

    if (viewName === 'onboarding') {
        return isSystemAdmin();
    }

    if (['integrations', 'widgets', 'users', 'audit', 'widget-abuse', 'order-status', 'ai'].includes(viewName)) {
        return hasRoleAtLeast('admin');
    }

    if (viewName === 'imports') {
        return hasRoleAtLeast('editor');
    }

    return true;
}

function updateAuthSurfaceVisibility() {
    const isAuthenticated = Boolean(state.token && state.tenantSlug);

    elements.loginCard?.classList.toggle('hidden', isAuthenticated);
    elements.loggedInCard?.classList.toggle('hidden', !isAuthenticated);
    elements.sidebarNav?.classList.toggle('hidden', !isAuthenticated);
    elements.mainContent?.classList.toggle('hidden', !isAuthenticated);
    elements.logoutButton?.classList.toggle('hidden', !isAuthenticated);
}

function applyRoleBasedNavigationVisibility() {
    const isAuthenticated = Boolean(state.token && state.tenantSlug);
    let activeVisibleView = null;

    elements.navButtons.forEach((button) => {
        const view = String(button.dataset.view ?? '');
        const allowed = isAuthenticated && canAccessView(view);
        button.classList.toggle('hidden', !allowed);

        if (allowed && button.classList.contains('active')) {
            activeVisibleView = view;
        }
    });

    if (!isAuthenticated) {
        return;
    }

    if (!activeVisibleView) {
        activateView('dashboard');
    }
}

function formatTimestamp(value) {
    if (!value) {
        return '-';
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return String(value);
    }

    return date.toLocaleString('sv-SE');
}

function showAlert(message, type = 'ok') {
    if (!elements.alert) {
        return;
    }

    elements.alert.classList.remove('hidden', 'ok', 'error');
    elements.alert.classList.add(type);
    elements.alert.textContent = message;

    window.clearTimeout(showAlert.timer);
    showAlert.timer = window.setTimeout(() => {
        elements.alert.classList.add('hidden');
    }, 3800);
}

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}
