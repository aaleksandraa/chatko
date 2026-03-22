<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Chatko Admin</title>
    @if (! app()->environment('testing'))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
</head>
<body class="chatko-body">
    <div class="ambient ambient-one"></div>
    <div class="ambient ambient-two"></div>

    <header class="topbar">
        <div>
            <p class="eyebrow">AI Sales Assistant</p>
            <h1 class="brand-title">Chatko Control Room</h1>
        </div>
        <div class="session-box">
            <p id="session-status">Niste prijavljeni.</p>
            <button id="logout-button" class="btn btn-ghost hidden" type="button">Logout</button>
        </div>
    </header>

    <div id="global-alert" class="alert hidden"></div>

    <div class="layout">
        <aside class="sidebar">
            <section id="login-card" class="card">
                <h2>Admin Login</h2>
                <form id="login-form" class="stack">
                    <label>Email
                        <input type="email" id="login-email" value="owner@demo.local" required>
                    </label>
                    <label>Password
                        <input type="password" id="login-password" value="password123" required>
                    </label>
                    <button class="btn" type="submit">Prijavi se</button>
                </form>
                <form id="forgot-password-form" class="stack">
                    <label>Forgot password email
                        <input type="email" id="forgot-password-email" placeholder="your-email@example.com" required>
                    </label>
                    <button class="btn btn-ghost" type="submit">Posalji reset link</button>
                </form>
            </section>

            <section id="logged-in-card" class="card hidden">
                <h2>Aktivna sesija</h2>
                <p class="muted">Vec ste prijavljeni. Nastavite rad kroz meni ili se odjavite ispod.</p>
                <div class="session-summary-grid">
                    <article class="session-summary-item">
                        <span class="session-summary-label">Korisnik</span>
                        <strong id="logged-in-name">-</strong>
                    </article>
                    <article class="session-summary-item">
                        <span class="session-summary-label">Email</span>
                        <strong id="logged-in-email">-</strong>
                    </article>
                    <article class="session-summary-item">
                        <span class="session-summary-label">Tenant</span>
                        <strong id="logged-in-tenant">-</strong>
                    </article>
                    <article class="session-summary-item">
                        <span class="session-summary-label">Role</span>
                        <strong id="logged-in-role">-</strong>
                    </article>
                </div>
                <button id="logged-in-logout-button" class="btn btn-ghost" type="button">Logout</button>
            </section>

            <nav id="sidebar-nav" class="card nav-stack hidden">
                <button class="nav-button active" data-view="dashboard" type="button">Dashboard</button>
                <button class="nav-button" data-view="onboarding" type="button">Tenant Onboarding</button>
                <button class="nav-button" data-view="tenants" type="button">Tenants</button>
                <button class="nav-button" data-view="users" type="button">Users</button>
                <button class="nav-button" data-view="integrations" type="button">Integrations</button>
                <button class="nav-button" data-view="widgets" type="button">Widgets</button>
                <button class="nav-button" data-view="products" type="button">Products</button>
                <button class="nav-button" data-view="knowledge" type="button">Knowledge</button>
                <button class="nav-button" data-view="conversations" type="button">Conversations</button>
                <button class="nav-button" data-view="imports" type="button">Import Jobs</button>
                <button class="nav-button" data-view="audit" type="button">Audit Logs</button>
                <button class="nav-button" data-view="widget-abuse" type="button">Widget Abuse Logs</button>
                <button class="nav-button" data-view="order-status" type="button">Order Status Events</button>
                <button class="nav-button" data-view="ai" type="button">AI Config</button>
                <button class="nav-button" data-view="widget" type="button">Widget Lab</button>
            </nav>
        </aside>

        <main id="app-main-content" class="content hidden">
            <section id="view-dashboard" class="view-panel active">
                <div class="panel-head">
                    <h2>Overview</h2>
                    <button id="refresh-overview" class="btn btn-ghost" type="button">Refresh</button>
                </div>
                <div class="stats-grid">
                    <article class="stat-card">
                        <p class="label">Conversations</p>
                        <p id="stat-conversations" class="value">-</p>
                    </article>
                    <article class="stat-card">
                        <p class="label">Leads</p>
                        <p id="stat-leads" class="value">-</p>
                    </article>
                    <article class="stat-card">
                        <p class="label">Events</p>
                        <p id="stat-events" class="value">-</p>
                    </article>
                    <article class="stat-card">
                        <p class="label">Lead Rate</p>
                        <p id="stat-lead-rate" class="value">-</p>
                    </article>
                </div>
                <section class="card">
                    <h3>Top Events</h3>
                    <ul id="top-events-list" class="simple-list"></ul>
                </section>
            </section>

            <section id="view-onboarding" class="view-panel">
                <h2>Tenant Onboarding Wizard</h2>
                <section class="card">
                    <p class="muted">Koraci: 1) Tenant + Owner, 2) Widget, 3) AI Config. Integracije tenant dodaje kasnije iz svog Integrations taba.</p>
                    <div id="onboarding-steps" class="wizard-steps">
                        <button class="wizard-step active" data-step="1" type="button">Step 1</button>
                        <button class="wizard-step" data-step="2" type="button">Step 2</button>
                        <button class="wizard-step" data-step="3" type="button">Step 3</button>
                    </div>

                    <form id="onboarding-form" class="stack">
                        <div class="wizard-panel active" data-step="1">
                            <label>Tenant name <input id="onboarding-tenant-name" type="text" required></label>
                            <label>Tenant slug <input id="onboarding-tenant-slug" type="text" placeholder="auto if empty"></label>
                            <label>Owner name <input id="onboarding-owner-name" type="text" required></label>
                            <label>Owner email <input id="onboarding-owner-email" type="email" required></label>
                            <label>Owner password <input id="onboarding-owner-password" type="password" required></label>
                            <label>Confirm password <input id="onboarding-owner-password-confirmation" type="password" required></label>
                        </div>

                        <div class="wizard-panel" data-step="2">
                            <label>Widget name <input id="onboarding-widget-name" type="text" value="Main Widget"></label>
                            <label>Widget locale <input id="onboarding-widget-locale" type="text" value="bs"></label>
                            <label>Allowed domains JSON
                                <textarea id="onboarding-widget-domains" rows="3" placeholder='["http://localhost"]'></textarea>
                            </label>
                            <label>Theme JSON
                                <textarea id="onboarding-widget-theme" rows="3" placeholder='{"primary_color":"#005f73"}'></textarea>
                            </label>
                        </div>

                        <div class="wizard-panel hidden" data-step="99" aria-hidden="true">
                            <label><input id="onboarding-integration-enabled" type="checkbox"> Create initial integration (auto when URL/credentials are filled)</label>
                            <label>Integration name <input id="onboarding-integration-name" type="text" value="Primary Source"></label>
                            <label>Integration type
                                <select id="onboarding-integration-type">
                                    <option value="woocommerce">WooCommerce</option>
                                    <option value="wordpress_rest">WordPress REST</option>
                                    <option value="shopify">Shopify</option>
                                    <option value="custom_api">Custom API</option>
                                    <option value="manual">Manual</option>
                                </select>
                            </label>
                            <p id="onboarding-integration-guide" class="muted">
                                Izaberi tip integracije i prikazace se samo obavezna polja za taj provider.
                            </p>
                            <div class="integration-required-wrap">
                                <span class="required-legend-badge">Required fields</span>
                                <div id="onboarding-required-fields" class="required-fields-list" aria-live="polite"></div>
                            </div>
                            <label id="onboarding-base-url-wrap">Base URL <input id="onboarding-integration-base-url" type="text" placeholder="https://shop.example.com"></label>
                            <section id="onboarding-simple-card" class="card integration-simple-card">
                                <h4>Simple Setup (Recommended)</h4>
                                <p class="muted">Prikazana su samo polja koja su potrebna za odabrani integration type.</p>

                                <div id="onboarding-simple-woocommerce" class="integration-simple-group hidden">
                                    <label>Woo Consumer Key <input id="onboarding-woo-consumer-key" type="text" placeholder="ck_xxxxx"></label>
                                    <label>Woo Consumer Secret <input id="onboarding-woo-consumer-secret" type="password" placeholder="cs_xxxxx"></label>
                                </div>

                                <div id="onboarding-simple-wordpress_rest" class="integration-simple-group hidden">
                                    <label>WP Auth Mode
                                        <select id="onboarding-wp-auth-mode">
                                            <option value="none">none (public endpoint)</option>
                                            <option value="basic">basic (username + app password)</option>
                                            <option value="bearer">bearer token</option>
                                        </select>
                                    </label>
                                    <div id="onboarding-wp-basic-fields" class="integration-simple-subgroup hidden">
                                        <label>WP Username <input id="onboarding-wp-username" type="text" placeholder="wp_api_user"></label>
                                        <label>WP App Password <input id="onboarding-wp-app-password" type="password" placeholder="xxxx xxxx xxxx xxxx"></label>
                                    </div>
                                    <div id="onboarding-wp-bearer-fields" class="integration-simple-subgroup hidden">
                                        <label>WP Bearer Token <input id="onboarding-wp-token" type="password" placeholder="token_..."></label>
                                    </div>
                                    <label>WordPress Resource Path <input id="onboarding-wp-resource-path" type="text" placeholder="/wp-json/wp/v2/posts"></label>
                                </div>

                                <div id="onboarding-simple-shopify" class="integration-simple-group hidden">
                                    <label>Shopify Access Token <input id="onboarding-shopify-access-token" type="password" placeholder="shpat_xxxxx"></label>
                                </div>

                                <div id="onboarding-simple-custom_api" class="integration-simple-group hidden">
                                    <label>Custom API Auth Mode
                                        <select id="onboarding-custom-auth-mode">
                                            <option value="bearer">bearer token</option>
                                            <option value="basic">basic</option>
                                            <option value="api_key_header">api key header</option>
                                            <option value="api_key_query">api key query</option>
                                            <option value="none">none</option>
                                        </select>
                                    </label>
                                    <div id="onboarding-custom-bearer-fields" class="integration-simple-subgroup hidden">
                                        <label>API Token <input id="onboarding-custom-token" type="password" placeholder="api_token"></label>
                                    </div>
                                    <div id="onboarding-custom-basic-fields" class="integration-simple-subgroup hidden">
                                        <label>API Username <input id="onboarding-custom-username" type="text"></label>
                                        <label>API Password <input id="onboarding-custom-password" type="password"></label>
                                    </div>
                                    <div id="onboarding-custom-api-header-fields" class="integration-simple-subgroup hidden">
                                        <label>API Key <input id="onboarding-custom-api-key" type="password"></label>
                                    </div>
                                    <div id="onboarding-custom-api-query-fields" class="integration-simple-subgroup hidden">
                                        <label>API Key <input id="onboarding-custom-api-key-query" type="password"></label>
                                    </div>
                                    <label>Products endpoint <input id="onboarding-custom-products-endpoint" type="text" placeholder="/products"></label>
                                </div>
                            </section>

                            <button id="onboarding-advanced-toggle" class="btn btn-ghost integration-advanced-toggle" type="button">Show Advanced Options</button>
                            <div id="onboarding-advanced-wrap" class="hidden">
                                <details class="integration-advanced" open>
                                    <summary>Advanced Overrides (optional)</summary>
                                    <p class="muted">Koristi samo ako zelis rucno override values. Ako ostavis prazno, koristi se Simple Setup.</p>
                                    <label>Auth Type Override (optional) <input id="onboarding-integration-auth-type" type="text" placeholder="basic / bearer / api_key_header ..."></label>
                                    <label>Credentials JSON Override
                                        <textarea id="onboarding-integration-credentials" rows="3" placeholder='{"token":"..."}'></textarea>
                                    </label>
                                    <label>Config JSON Override
                                        <textarea id="onboarding-integration-config" rows="3" placeholder='{"resource_path":"/wp-json/wp/v2/posts"}'></textarea>
                                    </label>
                                    <label>Mapping JSON Override
                                        <textarea id="onboarding-integration-mapping" rows="3" placeholder='{"name":"title.rendered"}'></textarea>
                                    </label>
                                </details>
                                <button id="onboarding-integration-apply-template" class="btn btn-ghost" type="button">Apply Type Template</button>
                            </div>
                        </div>
                        <div class="wizard-panel" data-step="3">
                            <label>Provider <input id="onboarding-ai-provider" type="text" value="openai"></label>
                            <label>Model <input id="onboarding-ai-model" type="text" value="gpt-5-mini"></label>
                            <label>Embedding model <input id="onboarding-ai-embedding" type="text" value="text-embedding-3-small"></label>
                            <label>Temperature <input id="onboarding-ai-temperature" type="number" step="0.1" min="0" max="2" value="0.3"></label>
                            <label>Max output tokens <input id="onboarding-ai-max-tokens" type="number" min="128" max="4000" value="350"></label>
                        </div>

                        <div class="wizard-actions">
                            <button id="onboarding-prev" class="btn btn-ghost" type="button">Previous</button>
                            <button id="onboarding-next" class="btn btn-ghost" type="button">Next</button>
                            <button id="onboarding-submit" class="btn" type="submit">Create Tenant</button>
                        </div>
                    </form>
                </section>
            </section>

            <section id="view-tenants" class="view-panel">
                <h2>Tenant Management</h2>
                <section class="card">
                    <div class="panel-head">
                        <h3>My Tenants</h3>
                        <button id="tenants-load" class="btn btn-ghost" type="button">Reload</button>
                    </div>
                    <p class="muted">Switch tenant context, edit settings (owner/admin), or delete tenant (owner only).</p>
                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Slug</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Current</th>
                                <th>Actions</th>
                            </tr>
                            </thead>
                            <tbody id="tenants-table-body"></tbody>
                        </table>
                    </div>
                </section>
            </section>

            <section id="view-users" class="view-panel">
                <h2>User Management</h2>
                <div class="split-grid">
                    <section class="card">
                        <h3>Add / Invite User</h3>
                        <p class="muted">Ako email vec postoji, korisnik ce biti dodat u tenant sa izabranom rolom.</p>
                        <form id="user-create-form" class="stack">
                            <label>Name <input id="user-name" type="text" placeholder="Ime i prezime"></label>
                            <label>Email <input id="user-email" type="email" required></label>
                            <label>Role
                                <select id="user-role">
                                    <option value="support">support</option>
                                    <option value="editor">editor</option>
                                    <option value="admin">admin</option>
                                    <option value="owner">owner</option>
                                </select>
                            </label>
                            <label id="user-system-admin-wrap" class="hidden">
                                <input id="user-system-admin" type="checkbox">
                                Grant system admin (platform-level)
                            </label>
                            <label>Password (required for new account) <input id="user-password" type="password" minlength="8"></label>
                            <label>Confirm password <input id="user-password-confirmation" type="password" minlength="8"></label>
                            <button class="btn" type="submit">Save User</button>
                        </form>
                    </section>

                    <section class="card">
                        <div class="panel-head">
                            <h3>Tenant Users</h3>
                            <button id="users-load" class="btn btn-ghost" type="button">Reload</button>
                        </div>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>System</th>
                                    <th>Current</th>
                                    <th>Actions</th>
                                </tr>
                                </thead>
                                <tbody id="users-table-body"></tbody>
                            </table>
                        </div>
                    </section>
                </div>
            </section>

            <section id="view-integrations" class="view-panel">
                <h2>Integrations</h2>
                <div class="split-grid">
                    <section class="card">
                        <h3>Create Integration</h3>
                        <form id="integration-create-form" class="stack">
                            <label>Name <input id="integration-name" type="text" required></label>
                            <label>Type
                                <select id="integration-type">
                                    <option value="woocommerce">WooCommerce</option>
                                    <option value="wordpress_rest">WordPress REST</option>
                                    <option value="shopify">Shopify</option>
                                    <option value="custom_api">Custom API</option>
                                    <option value="csv">CSV</option>
                                    <option value="manual">Manual</option>
                                </select>
                            </label>
                            <label>Auto sync frequency
                                <select id="integration-sync-frequency">
                                    <option value="every_5m">every_5m (najazurnije zalihe)</option>
                                    <option value="every_15m" selected>every_15m (recommended)</option>
                                    <option value="every_30m">every_30m</option>
                                    <option value="hourly">hourly</option>
                                    <option value="every_2h">every_2h</option>
                                    <option value="every_6h">every_6h</option>
                                    <option value="daily">daily</option>
                                    <option value="manual">manual (bez auto sync-a)</option>
                                </select>
                            </label>
                            <p id="integration-quick-guide" class="muted">
                                Izaberi tip integracije i prikazace se samo obavezna polja za taj provider.
                            </p>
                            <div class="integration-required-wrap">
                                <span class="required-legend-badge">Required fields</span>
                                <div id="integration-required-fields" class="required-fields-list" aria-live="polite"></div>
                            </div>
                            <label id="integration-base-url-wrap">Base URL <input id="integration-base-url" type="text" placeholder="https://shop.example.com"></label>
                            <section id="integration-simple-card" class="card integration-simple-card">
                                <h4>Simple Setup (Recommended)</h4>
                                <p class="muted">Prikazana su samo polja koja su potrebna za odabrani integration type.</p>

                                <div id="integration-simple-woocommerce" class="integration-simple-group hidden">
                                    <label>Woo Consumer Key <input id="integration-woo-consumer-key" type="text" placeholder="ck_xxxxx"></label>
                                    <label>Woo Consumer Secret <input id="integration-woo-consumer-secret" type="password" placeholder="cs_xxxxx"></label>
                                </div>

                                <div id="integration-simple-wordpress_rest" class="integration-simple-group hidden">
                                    <label>WP Auth Mode
                                        <select id="integration-wp-auth-mode">
                                            <option value="none">none (public endpoint)</option>
                                            <option value="basic">basic (username + app password)</option>
                                            <option value="bearer">bearer token</option>
                                        </select>
                                    </label>
                                    <div id="integration-wp-basic-fields" class="integration-simple-subgroup hidden">
                                        <label>WP Username <input id="integration-wp-username" type="text" placeholder="wp_api_user"></label>
                                        <label>WP App Password <input id="integration-wp-app-password" type="password" placeholder="xxxx xxxx xxxx xxxx"></label>
                                    </div>
                                    <div id="integration-wp-bearer-fields" class="integration-simple-subgroup hidden">
                                        <label>WP Bearer Token <input id="integration-wp-token" type="password" placeholder="token_..."></label>
                                    </div>
                                    <label>WordPress Resource Path <input id="integration-wp-resource-path" type="text" placeholder="/wp-json/wp/v2/posts"></label>
                                </div>

                                <div id="integration-simple-shopify" class="integration-simple-group hidden">
                                    <label>Shopify Access Token <input id="integration-shopify-access-token" type="password" placeholder="shpat_xxxxx"></label>
                                </div>

                                <div id="integration-simple-custom_api" class="integration-simple-group hidden">
                                    <label>Custom API Auth Mode
                                        <select id="integration-custom-auth-mode">
                                            <option value="bearer">bearer token</option>
                                            <option value="basic">basic</option>
                                            <option value="api_key_header">api key header</option>
                                            <option value="api_key_query">api key query</option>
                                            <option value="none">none</option>
                                        </select>
                                    </label>
                                    <div id="integration-custom-bearer-fields" class="integration-simple-subgroup hidden">
                                        <label>API Token <input id="integration-custom-token" type="password" placeholder="api_token"></label>
                                    </div>
                                    <div id="integration-custom-basic-fields" class="integration-simple-subgroup hidden">
                                        <label>API Username <input id="integration-custom-username" type="text"></label>
                                        <label>API Password <input id="integration-custom-password" type="password"></label>
                                    </div>
                                    <div id="integration-custom-api-header-fields" class="integration-simple-subgroup hidden">
                                        <label>API Key <input id="integration-custom-api-key" type="password"></label>
                                    </div>
                                    <div id="integration-custom-api-query-fields" class="integration-simple-subgroup hidden">
                                        <label>API Key <input id="integration-custom-api-key-query" type="password"></label>
                                    </div>
                                    <label>Products endpoint <input id="integration-custom-products-endpoint" type="text" placeholder="/products"></label>
                                </div>
                            </section>

                            <button id="integration-advanced-toggle" class="btn btn-ghost integration-advanced-toggle" type="button">Show Advanced Options</button>
                            <div id="integration-advanced-wrap" class="hidden">
                                <details class="integration-advanced" open>
                                    <summary>Advanced Overrides (optional)</summary>
                                    <p class="muted">Koristi samo ako zelis rucno override values. Ako ostavis prazno, koristi se Simple Setup.</p>
                                    <label>Auth Type Override (optional) <input id="integration-auth-type" type="text" placeholder="basic / bearer / api_key_header ..."></label>
                                    <label>Credentials JSON Override <textarea id="integration-credentials" rows="3" placeholder='{"token":"..."}'></textarea></label>
                                    <label>Config JSON Override <textarea id="integration-config" rows="3" placeholder='{"products_endpoint":"/products"}'></textarea></label>
                                    <label>Mapping JSON Override <textarea id="integration-mapping" rows="4" placeholder='{"name":"title"}'></textarea></label>
                                </details>
                                <button id="integration-apply-template" class="btn btn-ghost" type="button">Apply Type Template</button>
                            </div>
                            <button class="btn" type="submit">Save Integration</button>
                        </form>
                    </section>

                    <section class="card">
                        <div class="panel-head">
                            <h3>Integration List</h3>
                            <button id="integrations-load" class="btn btn-ghost" type="button">Reload</button>
                        </div>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th>Auth</th>
                                    <th>Creds</th>
                                    <th>Actions</th>
                                </tr>
                                </thead>
                                <tbody id="integrations-table-body"></tbody>
                            </table>
                        </div>
                    </section>
                </div>

                <section class="card">
                    <h3>Mapping Presets</h3>
                    <form id="preset-create-form" class="stack-inline">
                        <input id="preset-integration-id" type="number" min="1" placeholder="Integration ID" required>
                        <input id="preset-name" type="text" placeholder="Preset name" required>
                        <textarea id="preset-mapping" rows="3" placeholder='{"name":"title"}' required></textarea>
                        <button class="btn" type="submit">Create Preset</button>
                    </form>
                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Integration</th>
                                <th>Actions</th>
                            </tr>
                            </thead>
                            <tbody id="presets-table-body"></tbody>
                        </table>
                    </div>
                </section>
            </section>

            <section id="view-widgets" class="view-panel">
                <h2>Widgets</h2>
                <div class="split-grid">
                    <section class="card">
                        <h3>Create Widget</h3>
                        <form id="widget-create-form" class="stack">
                            <label>Name <input id="widget-create-name" type="text" required></label>
                            <label>Default locale <input id="widget-create-locale" type="text" value="bs"></label>
                            <label>Allowed domains JSON <textarea id="widget-create-domains" rows="3" placeholder='["http://localhost"]'></textarea></label>
                            <label>Theme JSON <textarea id="widget-create-theme" rows="3" placeholder='{"primary_color":"#005f73"}'></textarea></label>
                            <label><input id="widget-create-active" type="checkbox" checked> Active</label>
                            <button class="btn" type="submit">Create Widget</button>
                        </form>
                    </section>
                    <section class="card">
                        <div class="panel-head">
                            <h3>Widget List</h3>
                            <button id="widgets-load" class="btn btn-ghost" type="button">Reload</button>
                        </div>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Public key</th>
                                    <th>Active</th>
                                    <th>Actions</th>
                                </tr>
                                </thead>
                                <tbody id="widgets-table-body"></tbody>
                            </table>
                        </div>
                    </section>
                </div>
            </section>

            <section id="view-products" class="view-panel">
                <h2>Products</h2>
                <div class="split-grid">
                    <section class="card">
                        <h3>Create Product</h3>
                        <form id="product-create-form" class="stack">
                            <label>Name <input id="product-name" type="text" required></label>
                            <label>SKU <input id="product-sku" type="text"></label>
                            <label>Price <input id="product-price" type="number" step="0.01" min="0" required></label>
                            <label>Category <input id="product-category" type="text"></label>
                            <label>Brand <input id="product-brand" type="text"></label>
                            <label>Product URL <input id="product-url" type="url"></label>
                            <label><input id="product-in-stock" type="checkbox" checked> In stock</label>
                            <button class="btn" type="submit">Save Product</button>
                        </form>
                    </section>

                    <section class="card">
                        <div class="panel-head">
                            <h3>Product List</h3>
                            <form id="product-filter-form" class="stack-inline">
                                <input id="product-search" type="text" placeholder="Search by name, sku, category">
                                <select id="product-status">
                                    <option value="">All status</option>
                                    <option value="active">active</option>
                                    <option value="draft">draft</option>
                                </select>
                                <button class="btn btn-ghost" type="submit">Load</button>
                                <button id="products-delete-all" class="btn btn-danger hidden" type="button">Delete All</button>
                            </form>
                        </div>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Price</th>
                                    <th>Stock</th>
                                    <th>Category</th>
                                    <th>Actions</th>
                                </tr>
                                </thead>
                                <tbody id="products-table-body"></tbody>
                            </table>
                        </div>
                        <div class="table-pagination">
                            <button id="products-page-prev" class="btn btn-ghost" type="button">Previous</button>
                            <span id="products-page-info" class="muted">Page 1 / 1</span>
                            <button id="products-page-next" class="btn btn-ghost" type="button">Next</button>
                            <label class="pagination-size">Per page
                                <select id="products-per-page">
                                    <option value="25">25</option>
                                    <option value="50">50</option>
                                    <option value="100">100</option>
                                </select>
                            </label>
                            <span id="products-total-info" class="muted">Total: 0</span>
                        </div>
                    </section>
                </div>
            </section>

            <section id="view-knowledge" class="view-panel">
                <h2>Knowledge</h2>
                <div class="split-grid">
                    <section class="card">
                        <h3>Create Text Document</h3>
                        <form id="knowledge-text-form" class="stack">
                            <label>Title <input id="knowledge-title" type="text" required></label>
                            <label>Type
                                <select id="knowledge-type">
                                    <option value="faq">faq - cesto postavljena pitanja</option>
                                    <option value="shipping_policy">shipping_policy - dostava i rokovi</option>
                                    <option value="returns_policy">returns_policy - povrat i reklamacije</option>
                                    <option value="promotions">promotions - akcije, popusti, kuponi</option>
                                    <option value="payment_policy">payment_policy - nacini placanja</option>
                                    <option value="sales_playbook">sales_playbook - pravila prodajnog razgovora</option>
                                    <option value="product_education">product_education - kako odabrati proizvod</option>
                                    <option value="company_info">company_info - opste informacije o firmi</option>
                                </select>
                            </label>
                            <p id="knowledge-type-help" class="muted">Type definise temu dokumenta koju AI koristi u odgovoru.</p>
                            <label>Visibility
                                <select id="knowledge-visibility">
                                    <option value="public">public</option>
                                    <option value="private">private</option>
                                    <option value="disabled">disabled</option>
                                </select>
                            </label>
                            <label><input id="knowledge-ai-allowed" type="checkbox" checked> AI allowed</label>
                            <label>Template preset
                                <select id="knowledge-template-preset">
                                    <option value="">Bez templata</option>
                                    <option value="shipping_policy">Dostava i rokovi</option>
                                    <option value="returns_policy">Povrat i reklamacije</option>
                                    <option value="promotions">Akcije i popusti</option>
                                    <option value="payment_policy">Nacini placanja</option>
                                    <option value="sales_playbook">Prodajni playbook</option>
                                </select>
                            </label>
                            <button id="knowledge-apply-template" class="btn btn-ghost" type="button">Apply Template</button>
                            <label>Content
                                <textarea id="knowledge-content" rows="8" required></textarea>
                            </label>
                            <button class="btn" type="submit">Save Document</button>
                        </form>
                    </section>

                    <section class="card">
                        <div class="panel-head">
                            <h3>Knowledge List</h3>
                            <button id="knowledge-load" class="btn btn-ghost" type="button">Reload</button>
                        </div>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Title</th>
                                    <th>Status</th>
                                    <th>Type</th>
                                    <th>Actions</th>
                                </tr>
                                </thead>
                                <tbody id="knowledge-table-body"></tbody>
                            </table>
                        </div>
                    </section>
                </div>

                <section class="card knowledge-guide-card">
                    <div class="panel-head">
                        <h3>Knowledge Uputstvo</h3>
                    </div>
                    <p class="muted">Izaberi `type` i pogledaj sta unijeti u `content`, sa konkretnim primjerom koji mozes odmah ubaciti u formu.</p>
                    <div id="knowledge-guide-tabs" class="guide-tabs" role="tablist" aria-label="Knowledge type guide tabs"></div>
                    <div class="knowledge-guide-body">
                        <h4 id="knowledge-guide-current-type">faq</h4>
                        <p id="knowledge-guide-purpose" class="muted"></p>
                        <h5>Kako popuniti content</h5>
                        <ul id="knowledge-guide-checklist" class="simple-list"></ul>
                        <div class="table-actions">
                            <button id="knowledge-guide-use-type" class="btn btn-ghost" type="button">Koristi ovaj type u formi</button>
                            <button id="knowledge-guide-insert-example" class="btn btn-ghost" type="button">Ubaci primjer u content</button>
                        </div>
                        <label>Example title
                            <input id="knowledge-guide-example-title" type="text" readonly>
                        </label>
                        <label>Example content
                            <textarea id="knowledge-guide-example-content" rows="12" readonly></textarea>
                        </label>
                    </div>
                </section>
            </section>

            <section id="view-conversations" class="view-panel">
                <h2>Conversations</h2>
                <div class="split-grid">
                    <section class="card">
                        <div class="panel-head">
                            <h3>Conversation List</h3>
                            <button id="conversations-load" class="btn btn-ghost" type="button">Reload</button>
                        </div>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Status</th>
                                    <th>Order</th>
                                    <th>Session</th>
                                    <th>Actions</th>
                                </tr>
                                </thead>
                                <tbody id="conversations-table-body"></tbody>
                            </table>
                        </div>
                    </section>
                    <section class="card">
                        <h3>Messages</h3>
                        <div class="table-wrap">
                            <table class="conversation-messages-table">
                                <thead>
                                <tr>
                                    <th>Role</th>
                                    <th>Time</th>
                                    <th>Source</th>
                                    <th>Tokens In</th>
                                    <th>Tokens Out</th>
                                    <th>Message</th>
                                </tr>
                                </thead>
                                <tbody id="conversation-messages"></tbody>
                            </table>
                        </div>
                    </section>
                </div>
            </section>

            <section id="view-imports" class="view-panel">
                <h2>Import Jobs</h2>
                <section class="card">
                    <div class="panel-head">
                        <h3>Queue Status</h3>
                        <button id="imports-load" class="btn btn-ghost" type="button">Reload</button>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>ID</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Summary</th>
                                <th>Actions</th>
                            </tr>
                            </thead>
                            <tbody id="imports-table-body"></tbody>
                        </table>
                    </div>
                </section>
            </section>

            <section id="view-audit" class="view-panel">
                <h2>Audit Logs</h2>
                <section class="card">
                    <div class="panel-head">
                        <h3>Admin Actions</h3>
                        <button id="audit-load" class="btn btn-ghost" type="button">Reload</button>
                    </div>
                    <form id="audit-filter-form" class="stack-inline">
                        <select id="audit-filter-action">
                            <option value="">All actions</option>
                            <option value="created">created</option>
                            <option value="updated">updated</option>
                            <option value="deleted">deleted</option>
                            <option value="password_reset_requested">password_reset_requested</option>
                        </select>
                        <input id="audit-filter-entity" type="text" placeholder="Entity type (e.g. products)">
                        <input id="audit-filter-user" type="number" min="1" placeholder="Actor user ID">
                        <button class="btn btn-ghost" type="submit">Apply filters</button>
                    </form>
                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>ID</th>
                                <th>Action</th>
                                <th>Entity</th>
                                <th>Entity ID</th>
                                <th>Actor</th>
                                <th>Role</th>
                                <th>Path</th>
                                <th>At</th>
                            </tr>
                            </thead>
                            <tbody id="audit-table-body"></tbody>
                        </table>
                    </div>
                </section>
            </section>

            <section id="view-order-status" class="view-panel">
                <h2>Order Status Events</h2>
                <section class="card">
                    <div class="panel-head">
                        <h3>Webhook Status Timeline</h3>
                        <button id="order-status-load" class="btn btn-ghost" type="button">Reload</button>
                    </div>
                    <form id="order-status-filter-form" class="stack-inline">
                        <select id="order-status-filter-status">
                            <option value="">All statuses</option>
                            <option value="paid">paid</option>
                            <option value="shipped">shipped</option>
                            <option value="cancelled">cancelled</option>
                            <option value="processing">processing</option>
                            <option value="unknown">unknown</option>
                        </select>
                        <select id="order-status-filter-provider">
                            <option value="">All providers</option>
                            <option value="woocommerce">woocommerce</option>
                            <option value="shopify">shopify</option>
                            <option value="custom_api">custom_api</option>
                        </select>
                        <input id="order-status-filter-order-id" type="text" placeholder="Order ID">
                        <button class="btn btn-ghost" type="submit">Apply filters</button>
                    </form>
                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>ID</th>
                                <th>Status</th>
                                <th>Provider</th>
                                <th>Order ID</th>
                                <th>Message</th>
                                <th>Tracking</th>
                                <th>Conversation</th>
                                <th>At</th>
                            </tr>
                            </thead>
                            <tbody id="order-status-table-body"></tbody>
                        </table>
                    </div>
                </section>
            </section>

            <section id="view-widget-abuse" class="view-panel">
                <h2>Widget Abuse Logs</h2>
                <section class="card">
                    <div class="panel-head">
                        <h3>Blocked / Suspicious Widget Requests</h3>
                        <button id="widget-abuse-load" class="btn btn-ghost" type="button">Reload</button>
                    </div>
                    <form id="widget-abuse-filter-form" class="stack-inline">
                        <select id="widget-abuse-filter-reason">
                            <option value="">All reasons</option>
                            <option value="rate_limited">rate_limited</option>
                            <option value="origin_not_allowed">origin_not_allowed</option>
                            <option value="missing_origin">missing_origin</option>
                            <option value="missing_widget_session_token">missing_widget_session_token</option>
                            <option value="invalid_widget_session_signature">invalid_widget_session_signature</option>
                            <option value="expired_widget_session_token">expired_widget_session_token</option>
                            <option value="missing_challenge_token">missing_challenge_token</option>
                            <option value="challenge_verification_failed">challenge_verification_failed</option>
                            <option value="challenge_request_failed">challenge_request_failed</option>
                        </select>
                        <input id="widget-abuse-filter-ip" type="text" placeholder="IP address">
                        <input id="widget-abuse-filter-public-key" type="text" placeholder="Public key (wpk_...)">
                        <button class="btn btn-ghost" type="submit">Apply filters</button>
                    </form>
                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>ID</th>
                                <th>Reason</th>
                                <th>Public key</th>
                                <th>IP</th>
                                <th>Origin</th>
                                <th>Route</th>
                                <th>At</th>
                            </tr>
                            </thead>
                            <tbody id="widget-abuse-table-body"></tbody>
                        </table>
                    </div>
                </section>
            </section>

            <section id="view-ai" class="view-panel">
                <h2>AI Config</h2>
                <section class="card">
                    <form id="ai-config-form" class="stack">
                        <label>Provider <input id="ai-provider" type="text" value="openai"></label>
                        <label>Model name <input id="ai-model-name" type="text" list="ai-model-options" value="gpt-5.2"></label>
                        <datalist id="ai-model-options"></datalist>
                        <label>Embedding model <input id="ai-embedding-model" type="text" list="ai-embedding-options" value="text-embedding-3-small"></label>
                        <datalist id="ai-embedding-options"></datalist>
                        <p id="ai-model-help" class="muted">Allowed models: loading...</p>
                        <label>Temperature <input id="ai-temperature" type="number" step="0.1" min="0" max="2" value="0.2"></label>
                        <label>Max output tokens <input id="ai-max-tokens" type="number" min="128" max="4000" value="350"></label>
                        <label>Max messages monthly (empty = plan default) <input id="ai-max-messages-monthly" type="number" min="1" placeholder="5000"></label>
                        <label>Max tokens daily (optional) <input id="ai-max-tokens-daily" type="number" min="1" placeholder="50000"></label>
                        <label>Max tokens monthly (optional) <input id="ai-max-tokens-monthly" type="number" min="1" placeholder="1000000"></label>
                        <label><input id="ai-block-on-limit" type="checkbox" checked> Block chatbot when limit is reached</label>
                        <label><input id="ai-alert-on-limit" type="checkbox" checked> Send admin warning email when limit is reached</label>
                        <label>Top P <input id="ai-top-p" type="number" step="0.1" min="0" max="1" value="0.9"></label>
                        <label>System prompt template
                            <textarea id="ai-system-prompt" rows="7"></textarea>
                        </label>
                        <p id="ai-usage-summary" class="muted">Usage summary: -</p>
                        <button class="btn" type="submit">Save AI Config</button>
                    </form>
                </section>
            </section>

            <section id="view-widget" class="view-panel">
                <h2>Widget Lab</h2>
                <p class="muted">Widget Lab koristi admin test endpointe (tenant scoped), pa radi i kada produkcijski Allowed Domains ne ukljucuje admin domen.</p>
                <div class="split-grid">
                    <section class="card">
                        <form id="widget-session-form" class="stack">
                            <label>Widget public key <input id="widget-public-key" type="text" placeholder="wpk_..."></label>
                            <label>Source URL <input id="widget-source-url" type="url" placeholder="https://shop.example/product"></label>
                            <button class="btn" type="submit">Start Session</button>
                        </form>
                    </section>
                    <section class="card">
                        <form id="widget-message-form" class="stack">
                            <label>Message
                                <textarea id="widget-message" rows="3" placeholder="Treba mi proizvod za suhu kozu do 40 KM"></textarea>
                            </label>
                            <button class="btn" type="submit">Send Message</button>
                        </form>
                        <ul id="widget-chat-log" class="chat-log"></ul>
                    </section>
                </div>
            </section>
        </main>
    </div>

    <div id="entity-modal" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="entity-modal-title">
        <div class="modal-card">
            <div class="panel-head">
                <h3 id="entity-modal-title">Edit</h3>
                <button id="entity-modal-close" class="btn btn-ghost" type="button">Close</button>
            </div>
            <form id="entity-modal-form" class="stack">
                <div id="entity-modal-fields" class="stack"></div>
                <button class="btn" type="submit">Save Changes</button>
            </form>
        </div>
    </div>

    <div id="confirm-modal" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="confirm-modal-title">
        <div class="modal-card">
            <h3 id="confirm-modal-title">Confirm delete</h3>
            <p id="confirm-modal-message" class="muted"></p>
            <div class="table-actions">
                <button id="confirm-cancel" class="btn btn-ghost" type="button">Cancel</button>
                <button id="confirm-accept" class="btn btn-danger" type="button">Delete</button>
            </div>
        </div>
    </div>
</body>
</html>
