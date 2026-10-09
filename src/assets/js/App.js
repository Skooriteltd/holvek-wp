import { useState, useEffect, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, TextControl, TextareaControl, Notice, Spinner, ToggleControl } from '@wordpress/components';

import SetupWizard from './components/SetupWizard';
import GscTrendChart from './components/GscTrendChart';
import Ga4TrendChart from './components/Ga4TrendChart';
import Ga4ChannelChart from './components/Ga4ChannelChart';

function ollamaHostIsLoopback(url) {
    try {
        const parsed = new URL(url);
        if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
            return false;
        }
        const host = parsed.hostname.replace(/^\[|\]$/g, '').toLowerCase();
        if (host === 'localhost' || host.endsWith('.localhost') || host === '::1') {
            return true;
        }
        if (host.startsWith('::ffff:')) {
            return ollamaHostIsLoopback(`${parsed.protocol}//${host.slice(7)}`);
        }
        const parts = host.split('.');
        if (parts.length === 4 && parts.every((part) => /^\d+$/.test(part) && Number(part) >= 0 && Number(part) <= 255)) {
            return Number(parts[0]) === 127;
        }
        return false;
    } catch (error) {
        return false;
    }
}

const App = () => {
    const holvekAdminConfig = window.holvekAdminConfig || {};
    const [showSetup, setShowSetup] = useState(window.location.hash === '#setup');
    const [setupFinished, setSetupFinished] = useState(holvekAdminConfig.setupComplete === '1');
    const [activeTab, setActiveTab] = useState('dashboard');
    const [focusIndexing, setFocusIndexing] = useState(false);
    const [showHolvekNote, setShowHolvekNote] = useState(false);
    const [connection, setConnection] = useState(null);
    const [connectionLoading, setConnectionLoading] = useState(false);
    const [connectionError, setConnectionError] = useState('');
    const [connectionMessage, setConnectionMessage] = useState('');
    const [connectionBusy, setConnectionBusy] = useState(false);
    const [siteToken, setSiteToken] = useState('');
    const [authorizationCode, setAuthorizationCode] = useState('');
    const canConnectHolvek = !!(holvekAdminConfig.capabilities && holvekAdminConfig.capabilities.manage_options === true);

    const [standalone, setStandalone] = useState(null);
    const [standaloneLoading, setStandaloneLoading] = useState(false);
    const [standaloneError, setStandaloneError] = useState('');

    // GSC Dashboard — loaded only after the user asks for it.
    const [gscOpen, setGscOpen]         = useState(false);
    const [gscData, setGscData]         = useState(null);
    const [gscLoading, setGscLoading]   = useState(false);
    const [gscError, setGscError]       = useState(null);
    const [gscSubTab, setGscSubTab]     = useState('overview');
    const [cannData, setCannData]       = useState(null);
    const [cannLoading, setCannLoading] = useState(false);

    // GA4 Analytics
    const [ga4Report, setGa4Report]     = useState(null);
    const [ga4Loading, setGa4Loading]   = useState(false);
    const [ga4Error, setGa4Error]       = useState(null);
    const [ga4Settings, setGa4Settings] = useState({ measurement_id: '', property_id: '', credentials: '', credentials_set: false });
    const [ga4Saving, setGa4Saving]     = useState(false);
    const [ga4SaveMsg, setGa4SaveMsg]   = useState(null);

    // Link Health
    const [orphanData, setOrphanData]           = useState(null);
    const [orphanLoading, setOrphanLoading]     = useState(false);
    const [linkStats, setLinkStats]             = useState(null);
    const [linkStatsLoading, setLinkStatsLoading] = useState(false);
    const [linksSubTab, setLinksSubTab]         = useState('orphans');


    // Settings State
    const [settings, setSettings] = useState({
        google_indexing_key: '',
        indexnow_key: '',
        auto_index: false,
        enable_google_indexing_api: false,
        google_indexing_configured: false,
        indexnow_configured: false,
        delete_data_on_uninstall: false,
        google_verify: '',
        bing_verify: '',
        yandex_verify: '',
        pinterest_verify: '',
        breadcrumb_separator: '›',
        breadcrumb_home_label: 'Home',
        breadcrumb_auto_insert: 'none',
        ai_provider: 'openai',
        openai_key: '',
        anthropic_key: '',
        ollama_url: 'http://localhost:11434',
        ollama_remote_confirmed: false,
        default_share_image: '',
    });

    const [isTestingAi, setIsTestingAi] = useState(false);
    const [aiTestResult, setAiTestResult] = useState(null);

    const testAiConnection = async () => {
        setIsTestingAi(true);
        setAiTestResult(null);
        try {
            const response = await fetch(`${holvekAdminConfig.apiUrl}/settings/ai/test-connection`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': holvekAdminConfig.nonce,
                },
                body: JSON.stringify({
                    ai_provider: settings.ai_provider,
                    openai_key: settings.openai_key,
                    anthropic_key: settings.anthropic_key,
                    ollama_url: settings.ollama_url,
                    ollama_remote_confirmed: Boolean(settings.ollama_remote_confirmed)
                })
            });
            const data = await response.json();
            if (response.ok && data.success) {
                setAiTestResult({ type: 'success', message: data.message || 'Connection successful!' });
            } else {
                setAiTestResult({ type: 'error', message: data.message || 'Connection failed.' });
            }
        } catch (error) {
            setAiTestResult({ type: 'error', message: 'An error occurred during testing.' });
        } finally {
            setIsTestingAi(false);
        }
    };

    const openMediaLibrary = () => {
        if (!window.wp || !window.wp.media) return;
        const frame = window.wp.media({
            title: 'Select Default Share Image',
            multiple: false,
            library: { type: 'image' },
            button: { text: 'Use Image' }
        });
        frame.on('select', () => {
            const attachment = frame.state().get('selection').first().toJSON();
            setSettings(prev => ({ ...prev, default_share_image: attachment.url }));
        });
        frame.open();
    };
    const [isSaving, setIsSaving] = useState(false);
    const [saveStatus, setSaveStatus] = useState(null);
    const [isLoadingSettings, setIsLoadingSettings] = useState(true);

    useEffect(() => {
        if (activeTab !== 'dashboard' || standalone || standaloneLoading) {
            return;
        }
        setStandaloneLoading(true);
        setStandaloneError('');
        fetch(`${holvekAdminConfig.apiUrl}/dashboard/standalone`, { headers: { 'X-WP-Nonce': holvekAdminConfig.nonce } })
            .then(r => r.json().then(d => ({ ok: r.ok, d })))
            .then(({ ok, d }) => {
                if (!ok || d.code) {
                    setStandaloneError(d.message || __('The local results could not be loaded.', 'holvek'));
                    return;
                }
                if (d.next_step === 'active') {
                    setSetupFinished(true);
                }
                setStandalone(current => (current && current.next_step === 'active' ? current : d));
            })
            .catch(() => setStandaloneError(__('The local results could not be loaded.', 'holvek')))
            .finally(() => setStandaloneLoading(false));
    }, [activeTab]);

    useEffect(() => {
        if (!showHolvekNote || !canConnectHolvek) {
            return;
        }
        let cancelled = false;
        setConnectionLoading(true);
        setConnectionError('');
        fetch(`${holvekAdminConfig.apiUrl}/connection`, { headers: { 'X-WP-Nonce': holvekAdminConfig.nonce } })
            .then((response) => response.json().then((data) => ({ ok: response.ok, data })))
            .then(({ ok, data }) => {
                if (cancelled) {
                    return;
                }
                if (!ok || data.code) {
                    setConnectionError(data.message || __('The Holvek connection could not be loaded.', 'holvek'));
                    return;
                }
                setConnection(data);
            })
            .catch(() => {
                if (!cancelled) {
                    setConnectionError(__('The Holvek connection could not be loaded.', 'holvek'));
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setConnectionLoading(false);
                }
            });
        return () => {
            cancelled = true;
        };
    }, [showHolvekNote, canConnectHolvek]);

    const postConnection = (path, body) => {
        setConnectionBusy(true);
        setConnectionError('');
        setConnectionMessage('');
        const options = {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': holvekAdminConfig.nonce,
            },
        };
        if (body) {
            options.body = JSON.stringify(body);
        }
        fetch(`${holvekAdminConfig.apiUrl}${path}`, options)
            .then((response) => response.json().then((data) => ({ ok: response.ok, data })))
            .then(({ ok, data }) => {
                if (!ok || data.code) {
                    setConnectionError(data.message || __('Holvek did not accept this credential. Nothing was saved.', 'holvek'));
                    if (data.code === 'holvek_not_verified') {
                        setConnection((current) => (current ? { ...current, connected: false, tier: '' } : current));
                    }
                    return;
                }
                if (data.disconnected) {
                    setConnection((current) => ({
                        connected: false,
                        credential_saved: false,
                        tier: '',
                        host: 'https://api.holvek.com',
                        api_base: current && current.api_base ? current.api_base : '',
                    }));
                    setConnectionMessage(data.message || __('The Holvek credential was removed from this site. SEO data was kept.', 'holvek'));
                    setSiteToken('');
                    setAuthorizationCode('');
                    return;
                }
                setConnection(data);
                setConnectionMessage(__('This site is connected to Holvek.', 'holvek'));
                setSiteToken('');
                setAuthorizationCode('');
            })
            .catch(() => setConnectionError(__('Holvek could not be reached. This site keeps working without an account.', 'holvek')))
            .finally(() => setConnectionBusy(false));
    };

    useEffect(() => {
        if (activeTab !== 'settings' || !focusIndexing || isLoadingSettings) {
            return;
        }
        const node = document.getElementById('holvek-search-engine-notifications');
        if (node && typeof node.focus === 'function') {
            node.focus();
        }
    }, [activeTab, focusIndexing, isLoadingSettings]);

    useEffect(() => {
        // Search Console data is optional and stays unloaded until the user opens it.
        if (activeTab === 'dashboard' && gscOpen && !gscData && !gscLoading) {
            setGscLoading(true); setGscError(null);
            fetch(`${holvekAdminConfig.apiUrl}/gsc/dashboard`, { headers: { 'X-WP-Nonce': holvekAdminConfig.nonce } })
                .then(r => r.json())
                .then(d => { if (d.success) setGscData(d); else setGscError(d.message || __('Google Search Console is not connected. Add the service account JSON under Settings.', 'holvek')); })
                .catch(() => setGscError(__('Search Console data could not be loaded.', 'holvek')))
                .finally(() => setGscLoading(false));
        }

        // GA4 Analytics
        if (activeTab === 'analytics') {
            if (!ga4Report && !ga4Loading) {
                setGa4Loading(true); setGa4Error(null);
                fetch(`${holvekAdminConfig.apiUrl}/ga4/report`, { headers: { 'X-WP-Nonce': holvekAdminConfig.nonce } })
                    .then(r => r.json())
                    .then(d => { if (d.success) setGa4Report(d); else setGa4Error(d.message || 'GA4 not configured.'); })
                    .catch(() => setGa4Error('Failed to load GA4 data.'))
                    .finally(() => setGa4Loading(false));
            }
            if (!ga4Settings.measurement_id && !ga4Settings.credentials_set) {
                fetch(`${holvekAdminConfig.apiUrl}/settings/ga4`, { headers: { 'X-WP-Nonce': holvekAdminConfig.nonce } })
                    .then(r => r.json()).then(d => setGa4Settings(prev => ({ ...prev, ...d, credentials: '' })));
            }
        }

        // Link Health
        if (activeTab === 'links') {
            if (!orphanData && !orphanLoading) {
                loadOrphans(1);
            }
            if (!linkStats && !linkStatsLoading) {
                setLinkStatsLoading(true);
                fetch(`${holvekAdminConfig.apiUrl}/links/stats`, { headers: { 'X-WP-Nonce': holvekAdminConfig.nonce } })
                    .then(r => r.json()).then(d => { if (d.success) setLinkStats(d); }).finally(() => setLinkStatsLoading(false));
            }
        }

        if (activeTab === 'settings' && isLoadingSettings) {
            fetchSettings();
        }
    }, [activeTab, gscOpen]);

    // Cannibalization lazy-load only when sub-tab is selected
    useEffect(() => {
        if (activeTab === 'dashboard' && gscOpen && gscSubTab === 'cannibalization' && !cannData && !cannLoading) {
            setCannLoading(true);
            fetch(`${holvekAdminConfig.apiUrl}/gsc/cannibalization`, { headers: { 'X-WP-Nonce': holvekAdminConfig.nonce } })
                .then(r => r.json()).then(d => { if (d.success) setCannData(d); }).finally(() => setCannLoading(false));
        }
    }, [activeTab, gscOpen, gscSubTab]);

    const saveGa4Settings = useCallback(async () => {
        setGa4Saving(true); setGa4SaveMsg(null);
        try {
            const res = await fetch(`${holvekAdminConfig.apiUrl}/settings/ga4`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': holvekAdminConfig.nonce },
                body: JSON.stringify(ga4Settings),
            });
            const d = await res.json();
            setGa4SaveMsg(d.success ? { type: 'success', text: 'GA4 settings saved.' } : { type: 'error', text: d.message || 'Save failed.' });
            if (d.success) { setGa4Report(null); } // force reload on next visit
        } catch (e) { setGa4SaveMsg({ type: 'error', text: 'Network error.' }); }
        finally { setGa4Saving(false); }
    }, [ga4Settings]);

    // Chart/display helpers
    
    const posColor = (p) => p <= 3 ? '#22c55e' : p <= 10 ? '#f59e0b' : p <= 20 ? '#0ea5e9' : '#94a3b8';
    const fmtNum = (n) => n >= 1000000 ? (n / 1000000).toFixed(1) + 'M' : n >= 1000 ? (n / 1000).toFixed(1) + 'K' : String(n ?? 0);

    // ── Phase 3 state ────────────────────────────────────────────────────────
    // Import / Export
    const [exportMsg, setExportMsg]   = useState(null);
    const [importMsg, setImportMsg]   = useState(null);
    const [importBusy, setImportBusy] = useState(false);

    // HTML Sitemap
    const [sitemapCfg, setSitemapCfg]           = useState(null);
    const [sitemapPreview, setSitemapPreview]   = useState(null);
    const [sitemapSaving, setSitemapSaving]     = useState(false);
    const [sitemapSaveMsg, setSitemapSaveMsg]   = useState(null);
    const [sitemapLoading, setSitemapLoading]   = useState(false);

    // .htaccess editor
    const [htaccess, setHtaccess]               = useState(null);
    const [htaccessContent, setHtaccessContent] = useState('');
    const [htaccessSaving, setHtaccessSaving]   = useState(false);
    const [htaccessMsg, setHtaccessMsg]         = useState(null);
    const [htaccessLoading, setHtaccessLoading] = useState(false);

    // Phase 3 data fetch effects
    useEffect(() => {
        if (activeTab === 'html_sitemap' && !sitemapCfg && !sitemapLoading) {
            setSitemapLoading(true);
            fetch(`${holvekAdminConfig.apiUrl}/html-sitemap`, { headers: { 'X-WP-Nonce': holvekAdminConfig.nonce } })
                .then(r => r.json()).then(d => setSitemapCfg(d)).finally(() => setSitemapLoading(false));
        }
    }, [activeTab]);

    const loadSitemapPreview = useCallback(() => {
        fetch(`${holvekAdminConfig.apiUrl}/html-sitemap/preview`, { headers: { 'X-WP-Nonce': holvekAdminConfig.nonce } })
            .then(r => r.json()).then(d => setSitemapPreview(d));
    }, []);

    const saveSitemapCfg = useCallback(async () => {
        setSitemapSaving(true); setSitemapSaveMsg(null);
        try {
            const res = await fetch(`${holvekAdminConfig.apiUrl}/html-sitemap`, {
                method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': holvekAdminConfig.nonce },
                body: JSON.stringify(sitemapCfg),
            });
            const d = await res.json();
            setSitemapSaveMsg(d.success ? { type: 'success', text: 'Sitemap settings saved.' } : { type: 'error', text: d.message || 'Save failed.' });
            if (d.success) loadSitemapPreview();
        } catch (e) { setSitemapSaveMsg({ type: 'error', text: 'Network error.' }); }
        finally { setSitemapSaving(false); }
    }, [sitemapCfg, loadSitemapPreview]);

    const saveHtaccess = useCallback(async () => {
        setHtaccessSaving(true); setHtaccessMsg(null);
        try {
            const res = await fetch(`${holvekAdminConfig.apiUrl}/htaccess`, {
                method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': holvekAdminConfig.nonce },
                body: JSON.stringify({ content: htaccessContent }),
            });
            const d = await res.json();
            if (!res.ok || !d.success) {
                setHtaccessMsg({ type: 'error', text: d.message || 'Save failed.' });
                return;
            }
            setHtaccess(d);
            if (typeof d.content === 'string') setHtaccessContent(d.content);
            setHtaccessMsg({ type: 'success', text: d.notice || 'The .htaccess file was saved.' });
        } catch (e) { setHtaccessMsg({ type: 'error', text: 'Network error.' }); }
        finally { setHtaccessSaving(false); }
    }, [htaccessContent]);

    const setHtaccessEnabled = useCallback(async (enabled) => {
        setHtaccessSaving(true); setHtaccessMsg(null);
        try {
            const res = await fetch(`${holvekAdminConfig.apiUrl}/htaccess/access`, {
                method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': holvekAdminConfig.nonce },
                body: JSON.stringify({ enabled }),
            });
            const d = await res.json();
            if (!res.ok) {
                setHtaccessMsg({ type: 'error', text: d.message || 'Could not update the editor.' });
                return;
            }
            setHtaccess(d);
            setHtaccessContent(typeof d.content === 'string' ? d.content : '');
        } catch (e) { setHtaccessMsg({ type: 'error', text: 'Network error.' }); }
        finally { setHtaccessSaving(false); }
    }, []);

    const restoreHtaccessBackup = useCallback(async (filename) => {
        if (!window.confirm('Replace the current .htaccess file with this backup?')) return;
        setHtaccessSaving(true); setHtaccessMsg(null);
        try {
            const res = await fetch(`${holvekAdminConfig.apiUrl}/htaccess/restore`, {
                method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': holvekAdminConfig.nonce },
                body: JSON.stringify({ filename }),
            });
            const d = await res.json();
            if (!res.ok || !d.success) {
                setHtaccessMsg({ type: 'error', text: d.message || 'Restore failed.' });
                return;
            }
            setHtaccess(d);
            if (typeof d.content === 'string') setHtaccessContent(d.content);
            setHtaccessMsg({ type: 'success', text: d.notice || 'The backup was restored.' });
        } catch (e) { setHtaccessMsg({ type: 'error', text: 'Network error.' }); }
        finally { setHtaccessSaving(false); }
    }, []);

    const downloadExport = useCallback(async () => {
        setExportMsg(null);
        try {
            const res = await fetch(`${holvekAdminConfig.apiUrl}/settings/export`, { headers: { 'X-WP-Nonce': holvekAdminConfig.nonce } });
            const d = await res.json();
            if (!d.success) { setExportMsg({ type: 'error', text: 'Export failed.' }); return; }
            const blob = new Blob([JSON.stringify(d.data, null, 2)], { type: 'application/json' });
            const url  = URL.createObjectURL(blob);
            const a    = document.createElement('a');
            a.href = url; a.download = `holvek-settings-${new Date().toISOString().slice(0,10)}.json`; a.click();
            URL.revokeObjectURL(url);
            setExportMsg({ type: 'success', text: 'Settings file downloaded.' });
        } catch (e) { setExportMsg({ type: 'error', text: 'Export error.' }); }
    }, []);

    const handleImportFile = useCallback(async (file) => {
        if (!file) return;
        setImportBusy(true); setImportMsg(null);
        try {
            const text = await file.text();
            const json = JSON.parse(text);
            const res  = await fetch(`${holvekAdminConfig.apiUrl}/settings/import`, {
                method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': holvekAdminConfig.nonce },
                body: JSON.stringify(json),
            });
            const d = await res.json();
            setImportMsg(d.success ? { type: 'success', text: d.message } : { type: 'error', text: d.message || 'Import failed.' });
            if (d.success) { setIsLoadingSettings(true); fetchSettings(); }
        } catch (e) { setImportMsg({ type: 'error', text: 'Invalid file or parse error.' }); }
        finally { setImportBusy(false); }
    }, []);
    // ────────────────────────────────────────────────────────────────────────

    const fetchSettings = async () => {
        try {
            const response = await fetch(`${holvekAdminConfig.apiUrl}/settings`, {
                headers: {
                    'X-WP-Nonce': holvekAdminConfig.nonce,
                }
            });
            if (response.ok) {
                const data = await response.json();
                setSettings({
                    google_indexing_key: '',
                    indexnow_key: '',
                    google_indexing_configured: Boolean(data.google_indexing_configured),
                    indexnow_configured: Boolean(data.indexnow_configured),
                    auto_index: data.auto_index !== undefined ? data.auto_index : false,
                    enable_google_indexing_api: Boolean(data.enable_google_indexing_api),
                    delete_data_on_uninstall: Boolean(data.delete_data_on_uninstall),
                    google_verify: data.google_verify || '',
                    bing_verify: data.bing_verify || '',
                    yandex_verify: data.yandex_verify || '',
                    pinterest_verify: data.pinterest_verify || '',
                    breadcrumb_separator: data.breadcrumb_separator || '›',
                    breadcrumb_home_label: data.breadcrumb_home_label || 'Home',
                    breadcrumb_auto_insert: data.breadcrumb_auto_insert || 'none',
                    ai_provider: data.ai_provider || 'openai',
                    openai_key: data.openai_key || '',
                    anthropic_key: data.anthropic_key || '',
                    ollama_url: data.ollama_url || 'http://localhost:11434',
                    ollama_remote_confirmed: Boolean(data.ollama_remote_ack),
                    default_share_image: data.default_share_image || '',
                });
            }
        } catch (error) {
            console.error("Failed to fetch settings", error);
        } finally {
            setIsLoadingSettings(false);
        }
    };

    const [formErrors, setFormErrors] = useState({});

    const validateSettings = () => {
        const errors = {};
        if (settings.ai_provider === 'openai' && !settings.openai_key.trim()) {
            errors.openai_key = 'OpenAI API key is required when OpenAI is selected as the provider.';
        }
        if (settings.ai_provider === 'anthropic' && !settings.anthropic_key.trim()) {
            errors.anthropic_key = 'Anthropic API key is required when Anthropic is selected as the provider.';
        }
        if (settings.ai_provider === 'ollama' && !settings.ollama_url.trim()) {
            errors.ollama_url = 'Ollama URL is required when Ollama is selected as the provider.';
        }
        if (settings.ai_provider === 'ollama' && settings.ollama_url.trim() && !ollamaHostIsLoopback(settings.ollama_url) && !settings.ollama_remote_confirmed) {
            errors.ollama_url = 'Confirm that this remote Ollama host may receive post content.';
        }
        return errors;
    };

    const saveSettings = async () => {
        const errors = validateSettings();
        setFormErrors(errors);
        if (Object.keys(errors).length > 0) {
            setSaveStatus({ type: 'error', message: 'Please fix the errors below before saving.' });
            return;
        }

        setIsSaving(true);
        setSaveStatus(null);
        try {
            const response = await fetch(`${holvekAdminConfig.apiUrl}/settings`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': holvekAdminConfig.nonce,
                },
                body: JSON.stringify(settings)
            });
            
            if (response.ok) {
                setSaveStatus({ type: 'success', message: 'Settings saved successfully!' });
                setFormErrors({});
            } else {
                const data = await response.json().catch(() => ({}));
                setSaveStatus({ type: 'error', message: data.message || 'Failed to save settings.' });
            }
        } catch (error) {
            setSaveStatus({ type: 'error', message: 'An error occurred while saving.' });
        } finally {
            setIsSaving(false);
        }
    };


    // Robots.txt State
    const [robotsTxt, setRobotsTxt] = useState('');
    const [robotsReplace, setRobotsReplace] = useState(false);
    const [blockAiBots, setBlockAiBots] = useState(false);
    const [isLoadingRobots, setIsLoadingRobots] = useState(false);
    const [robotsLoaded, setRobotsLoaded] = useState(false);
    const [isSavingRobots, setIsSavingRobots] = useState(false);
    const [robotsStatus, setRobotsStatus] = useState(null);

    useEffect(() => {
        if (activeTab === 'technical' && !robotsLoaded) {
            setIsLoadingRobots(true);
            fetch(`${holvekAdminConfig.apiUrl}/robots-txt`, {
                headers: { 'X-WP-Nonce': holvekAdminConfig.nonce }
            })
            .then(r => r.json())
            .then(data => {
                setRobotsTxt(data.content || '');
                setRobotsReplace(!!data.replace_generated);
                setBlockAiBots(!!data.block_ai_bots);
                setRobotsLoaded(true);
            })
            .catch(() => {})
            .finally(() => setIsLoadingRobots(false));
        }
    }, [activeTab]);

    const saveRobotsTxt = async () => {
        setIsSavingRobots(true);
        setRobotsStatus(null);
        try {
            const replaceGenerated = robotsReplace && robotsTxt.trim() !== '';
            const response = await fetch(`${holvekAdminConfig.apiUrl}/robots-txt`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': holvekAdminConfig.nonce },
                body: JSON.stringify({
                    content: robotsTxt,
                    block_ai_bots: blockAiBots,
                    replace_generated: replaceGenerated,
                })
            });
            const data = await response.json().catch(() => ({}));
            if (response.ok) {
                setRobotsReplace(!!data.replace_generated);
            }
            setRobotsStatus(response.ok
                ? {
                    type: 'success',
                    message: data.replace_generated
                        ? 'Custom robots.txt now replaces the generated file.'
                        : 'Saved. Holvek keeps the generated robots.txt and adds its own rules.',
                }
                : { type: 'error', message: data.message || 'Failed to save settings.' }
            );
        } catch (error) {
            setRobotsStatus({ type: 'error', message: 'An error occurred.' });
        } finally {
            setIsSavingRobots(false);
        }
    };

    // Technical SEO Sub-Tabs
    const [techSubTab, setTechSubTab] = useState('robots');

    useEffect(() => {
        if (activeTab !== 'technical' || techSubTab !== 'htaccess' || htaccess || htaccessLoading) {
            return;
        }
        setHtaccessLoading(true);
        fetch(`${holvekAdminConfig.apiUrl}/htaccess`, { headers: { 'X-WP-Nonce': holvekAdminConfig.nonce } })
            .then(async (r) => {
                const d = await r.json();
                if (!r.ok) {
                    setHtaccess({
                        enabled: false,
                        locked: false,
                        forbidden: true,
                        message: d.message || 'You do not have permission to edit the server configuration.',
                        backups: [],
                    });
                    return;
                }
                setHtaccess(d);
                if (typeof d.content === 'string') setHtaccessContent(d.content);
            })
            .catch(() => setHtaccess({
                enabled: false,
                locked: false,
                message: 'The .htaccess editor could not be loaded.',
                backups: [],
            }))
            .finally(() => setHtaccessLoading(false));
    }, [activeTab, techSubTab, htaccess, htaccessLoading]);

    // Sitemaps State
    const [sitemapsData, setSitemapsData] = useState({
        enable_index_sitemap: false,
        enable_news_sitemap: false,
        enable_video_sitemap: false,
        exclude_types: [],
        exclude_posts: '',
        sitemap_changefreq_post: 'weekly',
        sitemap_changefreq_page: 'weekly',
        sitemap_priority_post: '0.6',
        sitemap_priority_page: '0.8',
        available_types: []
    });
    const [isLoadingSitemaps, setIsLoadingSitemaps] = useState(false);
    const [isSavingSitemaps, setIsSavingSitemaps] = useState(false);
    const [sitemapsStatus, setSitemapsStatus] = useState(null);

    // Global Schema Rules State
    const [schemaRules, setSchemaRules] = useState([]);
    const [availablePostTypes, setAvailablePostTypes] = useState([]);
    const [availableCategories, setAvailableCategories] = useState([]);
    const [isLoadingSchemaRules, setIsLoadingSchemaRules] = useState(false);
    const [isSavingSchemaRules, setIsSavingSchemaRules] = useState(false);
    const [schemaRulesStatus, setSchemaRulesStatus] = useState(null);

    const fetchSchemaRules = async () => {
        setIsLoadingSchemaRules(true);
        try {
            const r = await fetch(`${holvekAdminConfig.apiUrl}/schema/global-rules`, {
                headers: {
                    'X-WP-Nonce': holvekAdminConfig.nonce
                }
            });
            const data = await r.json();
            if (data) {
                setSchemaRules(data.rules || []);
                setAvailablePostTypes(data.post_types || []);
                setAvailableCategories(data.categories || []);
            }
        } catch (error) {
            console.error('Error fetching global rules:', error);
        }
        setIsLoadingSchemaRules(false);
    };

    const saveSchemaRules = async (newRules) => {
        setIsSavingSchemaRules(true);
        setSchemaRulesStatus(null);
        try {
            const r = await fetch(`${holvekAdminConfig.apiUrl}/schema/global-rules`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': holvekAdminConfig.nonce
                },
                body: JSON.stringify({ rules: newRules })
            });
            const data = await r.json();
            if (r.ok && data.success) {
                setSchemaRules(data.rules);
                setSchemaRulesStatus({ type: 'success', message: 'Global schema rules saved successfully!' });
            } else {
                setSchemaRulesStatus({ type: 'error', message: 'Failed to save global schema rules.' });
            }
        } catch (error) {
            setSchemaRulesStatus({ type: 'error', message: 'An error occurred while saving.' });
        }
        setIsSavingSchemaRules(false);
    };

    // Social Accounts State
    const [socialAccounts, setSocialAccounts] = useState([]);
    const [globalAutoShare, setGlobalAutoShare] = useState(true);
    const [socialCategories, setSocialCategories] = useState([]);
    const [isLoadingSocial, setIsLoadingSocial] = useState(false);
    const [socialApps, setSocialApps] = useState({});
    const [isSavingSocialApps, setIsSavingSocialApps] = useState(false);
    const [editingAccount, setEditingAccount] = useState(null);
    const [isSavingAccount, setIsSavingAccount] = useState(false);
    
    const fetchSocialAccounts = async () => {
        setIsLoadingSocial(true);
        try {
            const r = await fetch(`${holvekAdminConfig.apiUrl}/social/accounts`, {
                headers: { 'X-WP-Nonce': holvekAdminConfig.nonce }
            });
            const data = await r.json();
            if (data) {
                setSocialAccounts(data.accounts || []);
                setGlobalAutoShare(data.global_auto_share !== undefined ? data.global_auto_share : true);
                setSocialCategories(data.categories || []);
            }
            
            const rSettings = await fetch(`${holvekAdminConfig.apiUrl}/social/settings`, {
                headers: { 'X-WP-Nonce': holvekAdminConfig.nonce }
            });
            const settingsData = await rSettings.json();
            if (settingsData && settingsData.apps) {
                setSocialApps(settingsData.apps);
            }
        } catch (e) {}
        setIsLoadingSocial(false);
    };

    const updateSocialAccount = async (id, payload) => {
        setIsSavingAccount(true);
        try {
            const r = await fetch(`${holvekAdminConfig.apiUrl}/social/accounts/${id}`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': holvekAdminConfig.nonce },
                body: JSON.stringify(payload)
            });
            const data = await r.json();
            if (data.success) {
                setSocialAccounts(data.accounts || []);
                setEditingAccount(null);
            }
        } catch (e) {}
        setIsSavingAccount(false);
    };

    const deleteSocialAccount = async (id) => {
        if (!confirm('Are you sure you want to disconnect this account?')) return;
        try {
            const r = await fetch(`${holvekAdminConfig.apiUrl}/social/accounts/${id}`, {
                method: 'DELETE',
                headers: { 'X-WP-Nonce': holvekAdminConfig.nonce }
            });
            const data = await r.json();
            if (data.success) {
                setSocialAccounts(data.accounts);
            }
        } catch (e) {}
    };

    const toggleGlobalAutoShare = async (val) => {
        setGlobalAutoShare(val);
        try {
            await fetch(`${holvekAdminConfig.apiUrl}/social/accounts`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': holvekAdminConfig.nonce },
                body: JSON.stringify({ global_auto_share: val })
            });
        } catch (e) {}
    };

    const handleOAuthConnect = async (network) => {
        if (!socialApps[network] || !socialApps[network].app_id) {
            alert(`Please configure your ${network} App ID and Secret in the Platform API Settings first.`);
            return;
        }

        try {
            const res = await fetch(`${holvekAdminConfig.apiUrl}/social/oauth-init`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': holvekAdminConfig.nonce,
                },
                body: JSON.stringify({ network })
            });
            const data = await res.json();
            if (data.success && data.auth_url) {
                window.location.href = data.auth_url;
            } else {
                alert(data.message || 'Failed to initiate secure OAuth authorization.');
            }
        } catch (err) {
            alert('An error occurred while connecting to the authorization server.');
        }
    };

    const saveSocialSettings = async () => {
        setIsSavingSocialApps(true);
        try {
            const r = await fetch(`${holvekAdminConfig.apiUrl}/social/settings`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': holvekAdminConfig.nonce },
                body: JSON.stringify(socialApps)
            });
            const data = await r.json();
            if (data.success && data.apps) {
                setSocialApps(data.apps);
            }
        } catch (e) {}
        setIsSavingSocialApps(false);
    };

    // Redirects State
    const [redirects, setRedirects] = useState([]);
    const [redirectPage, setRedirectPage] = useState(1);
    const [redirectTotalPages, setRedirectTotalPages] = useState(1);
    const [isLoadingRedirects, setIsLoadingRedirects] = useState(false);
    const [newRedirect, setNewRedirect] = useState({ source: '', target: '', code: 301, is_regex: false });
    const [redirectsStatus, setRedirectsStatus] = useState(null);

    // 404 logs State
    const [logs404, setLogs404] = useState([]);
    const [logs404Page, setLogs404Page] = useState(1);
    const [logs404TotalPages, setLogs404TotalPages] = useState(1);
    const [isLoading404, setIsLoading404] = useState(false);

    // Custom 404 page selector state
    const [custom404PageId, setCustom404PageId] = useState(0);
    const [available404Pages, setAvailable404Pages] = useState([]);
    const [isLoading404Page, setIsLoading404Page] = useState(false);
    const [isSaving404Page, setIsSaving404Page] = useState(false);
    const [custom404Msg, setCustom404Msg] = useState(null);

    // Cookie banner settings state
    const [cookieBanner, setCookieBanner] = useState({ enabled: false, message: '', accept_label: 'Accept', decline_label: 'Decline', policy_url: '' });
    const [isLoadingCookieBanner, setIsLoadingCookieBanner] = useState(false);
    const [isSavingCookieBanner, setIsSavingCookieBanner] = useState(false);
    const [cookieBannerMsg, setCookieBannerMsg] = useState(null);

    // Image compression state
    const [compressionStats, setCompressionStats] = useState(null);
    const [compressionConfig, setCompressionConfig] = useState({ enabled: false, quality: 82, webp: false, preserve: true });
    const [compressionAcknowledged, setCompressionAcknowledged] = useState(false);
    const [compressionBackups, setCompressionBackups] = useState([]);
    const [isLoadingCompression, setIsLoadingCompression] = useState(false);
    const [isSavingCompression, setIsSavingCompression] = useState(false);
    const [isBulkCompressing, setIsBulkCompressing] = useState(false);
    const [compressionProgress, setCompressionProgress] = useState(null);
    const [compressionMsg, setCompressionMsg] = useState(null);

    // Image alt audit state
    const [altAuditItems, setAltAuditItems] = useState([]);
    const [altAuditTotal, setAltAuditTotal] = useState(0);
    const [altAuditPage, setAltAuditPage] = useState(1);
    const [isLoadingAltAudit, setIsLoadingAltAudit] = useState(false);
    const [isAutoFillingAlt, setIsAutoFillingAlt] = useState(false);
    const [altAuditMsg, setAltAuditMsg] = useState(null);
    const [altEdits, setAltEdits] = useState({});
    const [logs404Status, setLogs404Status] = useState(null);

    // Fetch Sitemaps Config
    const fetchSitemapsSettings = async () => {
        setIsLoadingSitemaps(true);
        try {
            const r = await fetch(`${holvekAdminConfig.apiUrl}/settings/sitemaps`, {
                headers: { 'X-WP-Nonce': holvekAdminConfig.nonce }
            });
            if (r.ok) {
                const data = await r.json();
                setSitemapsData(data);
            }
        } catch (e) {}
        setIsLoadingSitemaps(false);
    };

    // Save Sitemaps Config
    const saveSitemapsSettings = async () => {
        setIsSavingSitemaps(true);
        setSitemapsStatus(null);
        try {
            const r = await fetch(`${holvekAdminConfig.apiUrl}/settings/sitemaps`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': holvekAdminConfig.nonce },
                body: JSON.stringify(sitemapsData)
            });
            if (r.ok) {
                setSitemapsStatus({ type: 'success', message: 'Sitemap settings saved successfully!' });
            } else {
                setSitemapsStatus({ type: 'error', message: 'Failed to save sitemap settings.' });
            }
        } catch (e) {
            setSitemapsStatus({ type: 'error', message: 'An error occurred.' });
        }
        setIsSavingSitemaps(false);
    };


    // Load Custom 404 page settings when sub-tab opens
    const loadCustom404 = async () => {
        setIsLoading404Page(true);
        try {
            const r = await fetch(`${holvekAdminConfig.apiUrl}/settings/404-page`, { headers: { 'X-WP-Nonce': holvekAdminConfig.nonce } });
            const d = await r.json();
            setCustom404PageId(d.page_id || 0);
            setAvailable404Pages(d.pages || []);
        } catch(e) {}
        setIsLoading404Page(false);
    };

    const saveCustom404Page = async () => {
        setIsSaving404Page(true); setCustom404Msg(null);
        try {
            const r = await fetch(`${holvekAdminConfig.apiUrl}/settings/404-page`, {
                method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': holvekAdminConfig.nonce },
                body: JSON.stringify({ page_id: custom404PageId }),
            });
            const d = await r.json();
            setCustom404Msg(d.success ? { type: 'success', text: 'Custom 404 page saved.' } : { type: 'error', text: 'Failed to save.' });
        } catch(e) { setCustom404Msg({ type: 'error', text: 'Network error.' }); }
        setIsSaving404Page(false);
    };

    // Load cookie banner settings
    const loadCookieBanner = async () => {
        setIsLoadingCookieBanner(true);
        try {
            const r = await fetch(`${holvekAdminConfig.apiUrl}/settings/cookie-banner`, { headers: { 'X-WP-Nonce': holvekAdminConfig.nonce } });
            const d = await r.json();
            setCookieBanner(d);
        } catch(e) {}
        setIsLoadingCookieBanner(false);
    };

    const saveCookieBanner = async () => {
        setIsSavingCookieBanner(true); setCookieBannerMsg(null);
        try {
            const r = await fetch(`${holvekAdminConfig.apiUrl}/settings/cookie-banner`, {
                method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': holvekAdminConfig.nonce },
                body: JSON.stringify(cookieBanner),
            });
            const d = await r.json();
            setCookieBannerMsg(d.success ? { type: 'success', text: 'Cookie banner settings saved.' } : { type: 'error', text: 'Failed to save.' });
        } catch(e) { setCookieBannerMsg({ type: 'error', text: 'Network error.' }); }
        setIsSavingCookieBanner(false);
    };

    // Load image compression stats + settings
    const loadCompressionData = async () => {
        setIsLoadingCompression(true);
        try {
            const [statsRes, cfgRes, backupsRes] = await Promise.all([
                fetch(`${holvekAdminConfig.apiUrl}/images/compression-stats`, { headers: { 'X-WP-Nonce': holvekAdminConfig.nonce } }),
                fetch(`${holvekAdminConfig.apiUrl}/settings/image-compression`, { headers: { 'X-WP-Nonce': holvekAdminConfig.nonce } }),
                fetch(`${holvekAdminConfig.apiUrl}/images/compression-backups`, { headers: { 'X-WP-Nonce': holvekAdminConfig.nonce } }),
            ]);
            const [stats, cfg, backups] = await Promise.all([statsRes.json(), cfgRes.json(), backupsRes.json()]);
            setCompressionStats(stats); setCompressionConfig(cfg); setCompressionAcknowledged(Boolean(cfg.enabled)); setCompressionBackups(backups.items || []);
        } catch(e) {}
        setIsLoadingCompression(false);
    };

    const saveCompressionConfig = async () => {
        setIsSavingCompression(true); setCompressionMsg(null);
        try {
            const r = await fetch(`${holvekAdminConfig.apiUrl}/settings/image-compression`, {
                method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': holvekAdminConfig.nonce },
                body: JSON.stringify({ ...compressionConfig, acknowledge_reversible_processing: compressionAcknowledged }),
            });
            const d = await r.json();
            setCompressionMsg(d.success ? { type: 'success', text: 'Settings saved. Originals will always be retained for restoration.' } : { type: 'error', text: d.message || 'Confirm the reversible-processing acknowledgement before enabling compression.' });
        } catch(e) { setCompressionMsg({ type: 'error', text: 'Network error.' }); }
        setIsSavingCompression(false);
    };

    const runBulkCompress = async () => {
        setIsBulkCompressing(true); setCompressionProgress(null);
        const processNext = async () => {
            try {
                const r = await fetch(`${holvekAdminConfig.apiUrl}/images/compress-bulk`, {
                    method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': holvekAdminConfig.nonce },
                    body: JSON.stringify({ batch_size: 20 }),
                });
                const d = await r.json();
                setCompressionProgress(d);
                if (!d.done) { setTimeout(processNext, 500); }
                else { setIsBulkCompressing(false); loadCompressionData(); }
            } catch(e) { setIsBulkCompressing(false); }
        };
        processNext();
    };

    const restoreCompressedImage = async (attachmentId) => {
        setCompressionMsg(null);
        try {
            const r = await fetch(`${holvekAdminConfig.apiUrl}/images/${attachmentId}/restore`, {
                method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': holvekAdminConfig.nonce },
                body: JSON.stringify({}),
            });
            const d = await r.json();
            if (!d.success) {
                setCompressionMsg({ type: 'error', text: d.message || 'Could not restore the original image.' });
                return;
            }
            setCompressionMsg({ type: 'success', text: 'Original image restored.' });
            loadCompressionData();
        } catch(e) { setCompressionMsg({ type: 'error', text: 'Network error while restoring the original image.' }); }
    };

    // Load alt audit items
    const loadAltAudit = async (page = 1) => {
        setIsLoadingAltAudit(true); setAltAuditMsg(null);
        try {
            const r = await fetch(`${holvekAdminConfig.apiUrl}/images/missing-alt?page=${page}&per_page=20`, { headers: { 'X-WP-Nonce': holvekAdminConfig.nonce } });
            const d = await r.json();
            setAltAuditItems(d.items || []); setAltAuditTotal(d.total || 0); setAltAuditPage(page);
        } catch(e) {}
        setIsLoadingAltAudit(false);
    };

    const autoFillAltText = async () => {
        setIsAutoFillingAlt(true); setAltAuditMsg(null);
        try {
            const r = await fetch(`${holvekAdminConfig.apiUrl}/images/auto-fill-alt`, {
                method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': holvekAdminConfig.nonce },
                body: JSON.stringify({}),
            });
            const d = await r.json();
            setAltAuditMsg({ type: 'success', text: `Auto-filled alt text for ${d.updated} images.` });
            loadAltAudit(1);
        } catch(e) { setAltAuditMsg({ type: 'error', text: 'Failed to auto-fill.' }); }
        setIsAutoFillingAlt(false);
    };

    const saveAltEdits = async () => {
        const items = Object.entries(altEdits).map(([id, alt]) => ({ id: Number(id), alt }));
        if (!items.length) return;
        try {
            const r = await fetch(`${holvekAdminConfig.apiUrl}/images/bulk-alt`, {
                method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': holvekAdminConfig.nonce },
                body: JSON.stringify({ items }),
            });
            const d = await r.json();
            setAltAuditMsg({ type: 'success', text: `Saved ${d.updated} alt texts.` });
            setAltEdits({}); loadAltAudit(altAuditPage);
        } catch(e) { setAltAuditMsg({ type: 'error', text: 'Failed to save.' }); }
    };

    // ── RBAC: Roles & Capabilities ───────────────────────────────────────────
    const [rolesData, setRolesData]         = useState(null);
    const [rolesLoading, setRolesLoading]   = useState(false);
    const [rolesSaving, setRolesSaving]     = useState(false);
    const [rolesMsg, setRolesMsg]           = useState(null);
    const [roleEdits, setRoleEdits]         = useState({});

    const loadRoles = async () => {
        setRolesLoading(true);
        try {
            const r = await fetch(`${holvekAdminConfig.apiUrl}/roles/capabilities`, {
                headers: { 'X-WP-Nonce': holvekAdminConfig.nonce },
            });
            const d = await r.json();
            setRolesData(d);
            // Seed edits from current values
            const initial = {};
            Object.entries(d.roles || {}).forEach(([slug, role]) => {
                initial[slug] = { ...role.capabilities };
            });
            setRoleEdits(initial);
        } catch(e) {}
        setRolesLoading(false);
    };

    const saveRoles = async () => {
        setRolesSaving(true); setRolesMsg(null);
        try {
            const r = await fetch(`${holvekAdminConfig.apiUrl}/roles/capabilities`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': holvekAdminConfig.nonce },
                body: JSON.stringify(roleEdits),
            });
            const d = await r.json();
            setRolesMsg(d.success ? { type: 'success', text: 'Capabilities saved.' } : { type: 'error', text: 'Failed to save.' });
        } catch(e) { setRolesMsg({ type: 'error', text: 'Network error.' }); }
        setRolesSaving(false);
    };

    const toggleCap = (roleSlug, cap, value) => {
        if (roleSlug === 'administrator') return; // Immutable
        setRoleEdits(prev => ({
            ...prev,
            [roleSlug]: { ...(prev[roleSlug] || {}), [cap]: value },
        }));
    };

    // ── Network SEO (Multisite) ───────────────────────────────────────────────
    const isMultisite = holvekAdminConfig.isMultisite === '1';
    const [networkSettings, setNetworkSettings]     = useState(null);
    const [networkSites, setNetworkSites]           = useState([]);
    const [networkLoading, setNetworkLoading]       = useState(false);
    const [networkSaving, setNetworkSaving]         = useState(false);
    const [networkMsg, setNetworkMsg]               = useState(null);

    const loadNetworkData = async () => {
        setNetworkLoading(true);
        try {
            const [settingsRes, sitesRes] = await Promise.all([
                fetch(`${holvekAdminConfig.apiUrl}/network/settings`, { headers: { 'X-WP-Nonce': holvekAdminConfig.nonce } }),
                fetch(`${holvekAdminConfig.apiUrl}/network/sites`, { headers: { 'X-WP-Nonce': holvekAdminConfig.nonce } }),
            ]);
            const [settings, sites] = await Promise.all([settingsRes.json(), sitesRes.json()]);
            setNetworkSettings(settings);
            setNetworkSites(sites.sites || []);
        } catch(e) {}
        setNetworkLoading(false);
    };

    const saveNetworkSettings = async () => {
        setNetworkSaving(true); setNetworkMsg(null);
        try {
            const r = await fetch(`${holvekAdminConfig.apiUrl}/network/settings`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': holvekAdminConfig.nonce },
                body: JSON.stringify(networkSettings),
            });
            const d = await r.json();
            setNetworkMsg(d.success ? { type: 'success', text: 'Network settings saved.' } : { type: 'error', text: 'Failed to save.' });
        } catch(e) { setNetworkMsg({ type: 'error', text: 'Network error.' }); }
        setNetworkSaving(false);
    };

    // ── Technical SEO Site Audit ─────────────────────────────────────────────
    const [auditResults, setAuditResults]     = useState(null);
    const [auditProgress, setAuditProgress]   = useState({ status: 'idle', percent: 0, current_check: '' });
    const [auditRunning, setAuditRunning]     = useState(false);
    const [auditPolling, setAuditPolling]     = useState(null);

    const loadAuditResults = async () => {
        try {
            const r = await fetch(`${holvekAdminConfig.apiUrl}/audit/technical`, { headers: { 'X-WP-Nonce': holvekAdminConfig.nonce } });
            const d = await r.json();
            if (d.checks) setAuditResults(d);
        } catch(e) {}
    };

    const runSiteAudit = async () => {
        setAuditRunning(true); setAuditResults(null);
        setAuditProgress({ status: 'running', percent: 0, current_check: 'Starting…' });
        try {
            await fetch(`${holvekAdminConfig.apiUrl}/audit/technical/run`, {
                method: 'POST', headers: { 'X-WP-Nonce': holvekAdminConfig.nonce },
            });
            // Poll progress every 3s until done
            const poll = setInterval(async () => {
                try {
                    const r = await fetch(`${holvekAdminConfig.apiUrl}/audit/technical/progress`, { headers: { 'X-WP-Nonce': holvekAdminConfig.nonce } });
                    const d = await r.json();
                    setAuditProgress(d);
                    if (d.status === 'done') {
                        clearInterval(poll);
                        setAuditRunning(false);
                        loadAuditResults();
                    }
                } catch(e) { clearInterval(poll); setAuditRunning(false); }
            }, 3000);
            setAuditPolling(poll);
        } catch(e) { setAuditRunning(false); }
    };

    // ── Broken Link Checker ───────────────────────────────────────────────────
    const [blcResults, setBlcResults]         = useState([]);
    const [blcTotal, setBlcTotal]             = useState(0);
    const [blcPage, setBlcPage]               = useState(1);
    const [blcProgress, setBlcProgress]       = useState({ status: 'idle', scanned: 0, total: 0, found: 0 });
    const [blcLoading, setBlcLoading]         = useState(false);
    const [blcScanning, setBlcScanning]       = useState(false);
    const [blcShowResolved, setBlcShowResolved] = useState(false);
    const [blcMsg, setBlcMsg]                 = useState(null);

    const loadBlcResults = async (page = 1, resolved = false) => {
        setBlcLoading(true);
        try {
            const r = await fetch(`${holvekAdminConfig.apiUrl}/broken-links?per_page=20&page=${page}&resolved=${resolved ? 1 : 0}`, {
                headers: { 'X-WP-Nonce': holvekAdminConfig.nonce },
            });
            const d = await r.json();
            setBlcResults(d.items || []); setBlcTotal(d.total || 0); setBlcPage(page);
        } catch(e) {}
        setBlcLoading(false);
    };

    const loadBlcProgress = async () => {
        try {
            const r = await fetch(`${holvekAdminConfig.apiUrl}/broken-links/progress`, { headers: { 'X-WP-Nonce': holvekAdminConfig.nonce } });
            const d = await r.json();
            setBlcProgress(d);
            return d.status;
        } catch(e) { return 'idle'; }
    };

    const startBlcScan = async () => {
        setBlcScanning(true); setBlcMsg(null);
        setBlcProgress({ status: 'running', scanned: 0, total: 0, found: 0 });
        try {
            const r = await fetch(`${holvekAdminConfig.apiUrl}/broken-links/scan`, {
                method: 'POST', headers: { 'X-WP-Nonce': holvekAdminConfig.nonce },
            });
            const d = await r.json();
            if (d.success) {
                setBlcMsg({ type: 'info', text: d.message });
                // Poll for progress
                const poll = setInterval(async () => {
                    const status = await loadBlcProgress();
                    if (status === 'done') {
                        clearInterval(poll);
                        setBlcScanning(false);
                        loadBlcResults(1, blcShowResolved);
                    }
                }, 4000);
            }
        } catch(e) { setBlcScanning(false); }
    };

    const resolveBlcLink = async (id) => {
        try {
            await fetch(`${holvekAdminConfig.apiUrl}/broken-links/${id}/resolve`, {
                method: 'POST', headers: { 'X-WP-Nonce': holvekAdminConfig.nonce },
            });
            loadBlcResults(blcPage, blcShowResolved);
        } catch(e) {}
    };

    const recheckBlcLink = async (id, url) => {
        setBlcMsg({ type: 'info', text: `Rechecking ${url}…` });
        try {
            const r = await fetch(`${holvekAdminConfig.apiUrl}/broken-links/recheck`, {
                method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': holvekAdminConfig.nonce },
                body: JSON.stringify({ id, url }),
            });
            const d = await r.json();
            if (d.queued) {
                setBlcMsg({ type: 'info', text: d.message || 'Recheck queued.' });
                return;
            }
            setBlcMsg({ type: d.broken ? 'error' : 'success', text: `Status: HTTP ${d.http_code} — ${d.broken ? 'Still broken' : 'Now reachable'}` });
            loadBlcResults(blcPage, blcShowResolved);
        } catch(e) { setBlcMsg({ type: 'error', text: 'Recheck failed.' }); }
    };

    // ── Keyword Rank Tracker ──────────────────────────────────────────────────
    const [rankKeywords, setRankKeywords]     = useState([]);
    const [rankHistory, setRankHistory]       = useState({});
    const [rankDays, setRankDays]             = useState(30);
    const [rankLoading, setRankLoading]       = useState(false);
    const [rankChecking, setRankChecking]     = useState(false);
    const [rankSaving, setRankSaving]         = useState(false);
    const [rankMsg, setRankMsg]               = useState(null);
    const [newKeyword, setNewKeyword]         = useState('');
    const [serpApiKey, setSerpApiKey]         = useState('');

    const loadRankData = async () => {
        setRankLoading(true);
        try {
            const [kwRes, histRes] = await Promise.all([
                fetch(`${holvekAdminConfig.apiUrl}/rank-tracker/keywords`, { headers: { 'X-WP-Nonce': holvekAdminConfig.nonce } }),
                fetch(`${holvekAdminConfig.apiUrl}/rank-tracker/history?days=${rankDays}`, { headers: { 'X-WP-Nonce': holvekAdminConfig.nonce } }),
            ]);
            const [kw, hist] = await Promise.all([kwRes.json(), histRes.json()]);
            setRankKeywords(kw.keywords || []);
            setRankHistory(hist.history || {});
            // Load SerpApi key from settings
            const sRes = await fetch(`${holvekAdminConfig.apiUrl}/settings`, { headers: { 'X-WP-Nonce': holvekAdminConfig.nonce } });
            const s = await sRes.json();
            setSerpApiKey(s.serpapi_key || '');
        } catch(e) {}
        setRankLoading(false);
    };

    const saveRankKeywords = async (keywords) => {
        setRankSaving(true); setRankMsg(null);
        try {
            const r = await fetch(`${holvekAdminConfig.apiUrl}/rank-tracker/keywords`, {
                method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': holvekAdminConfig.nonce },
                body: JSON.stringify({ keywords }),
            });
            const d = await r.json();
            setRankMsg(d.success ? { type: 'success', text: `${d.count} keywords saved.` } : { type: 'error', text: 'Failed to save.' });
            setRankKeywords(keywords);
        } catch(e) { setRankMsg({ type: 'error', text: 'Network error.' }); }
        setRankSaving(false);
    };

    const checkRankNow = async (keyword = null) => {
        setRankChecking(true); setRankMsg(null);
        try {
            const r = await fetch(`${holvekAdminConfig.apiUrl}/rank-tracker/check-now`, {
                method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': holvekAdminConfig.nonce },
                body: JSON.stringify(keyword ? { keyword } : {}),
            });
            const d = await r.json();
            if (!r.ok || d.code) {
                setRankMsg({ type: 'error', text: d.message || __('This site is not connected to Holvek, so cloud rank tracking is unavailable. You can keep using Holvek on this site.', 'holvek') });
                return;
            }
            if (d.queued) {
                setRankMsg({ type: 'info', text: d.message || 'Rank check queued.' });
                return;
            }
            if (d.results) {
                const errors = Object.values(d.results).filter(row => row.error);
                if (errors.length) {
                    setRankMsg({ type: 'error', text: errors[0].error });
                } else {
                    setRankMsg({ type: 'success', text: 'Rank check complete. Refreshing history…' });
                    setTimeout(() => loadRankData(), 1500);
                }
            }
        } catch(e) { setRankMsg({ type: 'error', text: 'Check failed.' }); }
        finally { setRankChecking(false); }
    };

    const addKeyword = () => {
        const kw = newKeyword.trim();
        if (!kw || rankKeywords.includes(kw)) return;
        const updated = [...rankKeywords, kw];
        setNewKeyword('');
        saveRankKeywords(updated);
    };

    const removeKeyword = (kw) => saveRankKeywords(rankKeywords.filter(k => k !== kw));

    // Fetch Redirects
    const fetchRedirects = async (page = 1) => {
        const nextPage = Math.max(1, page);
        setIsLoadingRedirects(true);
        try {
            const r = await fetch(`${holvekAdminConfig.apiUrl}/redirects?page=${nextPage}&per_page=50`, {
                headers: { 'X-WP-Nonce': holvekAdminConfig.nonce }
            });
            if (r.ok) {
                const data = await r.json();
                setRedirects(Array.isArray(data) ? data : (data.items || []));
                setRedirectPage(data.page || nextPage);
                setRedirectTotalPages(data.total_pages || 1);
            }
        } catch (e) {}
        setIsLoadingRedirects(false);
    };

    // Add Redirect
    const addRedirect = async () => {
        setRedirectsStatus(null);
        if (!newRedirect.source || !newRedirect.target) {
            setRedirectsStatus({ type: 'error', message: 'Source and Target paths are required.' });
            return;
        }
        try {
            const r = await fetch(`${holvekAdminConfig.apiUrl}/redirects`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': holvekAdminConfig.nonce },
                body: JSON.stringify(newRedirect)
            });
            const res = await r.json();
            if (r.ok) {
                setNewRedirect({ source: '', target: '', code: 301, is_regex: false });
                setRedirectsStatus({ type: 'success', message: 'Redirect rule added successfully!' });
                fetchRedirects(redirectPage);
            } else {
                setRedirectsStatus({ type: 'error', message: res.message || 'Failed to add redirect.' });
            }
        } catch (e) {
            setRedirectsStatus({ type: 'error', message: 'An error occurred.' });
        }
    };

    // Delete Redirect
    const deleteRedirect = async (id) => {
        setRedirectsStatus(null);
        try {
            const r = await fetch(`${holvekAdminConfig.apiUrl}/redirects/delete`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': holvekAdminConfig.nonce },
                body: JSON.stringify({ id })
            });
            const res = await r.json();
            if (r.ok) {
                setRedirectsStatus({ type: 'success', message: 'Redirect rule deleted.' });
                fetchRedirects(redirectPage);
            }
        } catch (e) {}
    };

    // Fetch 404 Logs
    const fetch404Logs = async (page = 1) => {
        const nextPage = Math.max(1, page);
        setIsLoading404(true);
        try {
            const r = await fetch(`${holvekAdminConfig.apiUrl}/errors/404?page=${nextPage}&per_page=50`, {
                headers: { 'X-WP-Nonce': holvekAdminConfig.nonce }
            });
            if (r.ok) {
                const data = await r.json();
                const rows = Array.isArray(data) ? data : (data.items || data.logs || []);
                setLogs404(rows);
                setLogs404Page(data.page || nextPage);
                setLogs404TotalPages(data.total_pages || 1);
            }
        } catch (e) {}
        setIsLoading404(false);
    };

    // Clear specific 404 Log
    const delete404Log = async (id) => {
        try {
            const r = await fetch(`${holvekAdminConfig.apiUrl}/errors/404`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': holvekAdminConfig.nonce },
                body: JSON.stringify({ id })
            });
            const res = await r.json();
            if (r.ok) {
                fetch404Logs(logs404Page);
            }
        } catch (e) {}
    };

    // Clear all 404 Logs
    const clearAll404Logs = async () => {
        try {
            const r = await fetch(`${holvekAdminConfig.apiUrl}/errors/404`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': holvekAdminConfig.nonce },
                body: JSON.stringify({})
            });
            const res = await r.json();
            if (r.ok) {
                setLogs404(res.logs || []);
                setLogs404Page(1);
                setLogs404TotalPages(1);
            }
        } catch (e) {}
    };

    const loadOrphans = (page = 1) => {
        const nextPage = Math.max(1, page);
        setOrphanLoading(true);
        fetch(`${holvekAdminConfig.apiUrl}/links/orphans?page=${nextPage}&per_page=50`, { headers: { 'X-WP-Nonce': holvekAdminConfig.nonce } })
            .then(r => r.json())
            .then(d => { if (d.success) setOrphanData(d); })
            .finally(() => setOrphanLoading(false));
    };

    // Quick add 404 URL to Redirect creation
    const convert404ToRedirect = (uri) => {
        setNewRedirect({ source: uri, target: '', code: 301, is_regex: false });
        setTechSubTab('redirects');
    };

    // LLM Agent Config State
    const [llmsData, setLlmsData] = useState({
        description: '',
        allow_index: true,
        allow_training: false,
        contact_url: '',
        extra_notes: '',
        preview: ''
    });
    const [isLoadingLlms, setIsLoadingLlms] = useState(false);
    const [isSavingLlms, setIsSavingLlms] = useState(false);
    const [llmsStatus, setLlmsStatus] = useState(null);

    const fetchLlmsSettings = async () => {
        setIsLoadingLlms(true);
        try {
            const r = await fetch(`${holvekAdminConfig.apiUrl}/llms-txt/config`, {
                headers: { 'X-WP-Nonce': holvekAdminConfig.nonce }
            });
            if (r.ok) {
                const data = await r.json();
                setLlmsData(data);
            }
        } catch (e) {}
        setIsLoadingLlms(false);
    };

    const saveLlmsSettings = async () => {
        setIsSavingLlms(true);
        setLlmsStatus(null);
        try {
            const r = await fetch(`${holvekAdminConfig.apiUrl}/llms-txt/config`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': holvekAdminConfig.nonce },
                body: JSON.stringify(llmsData)
            });
            if (r.ok) {
                const data = await r.json();
                setLlmsData(data.config);
                setLlmsStatus({ type: 'success', message: 'llms.txt configuration saved!' });
            } else {
                setLlmsStatus({ type: 'error', message: 'Failed to save llms.txt configuration.' });
            }
        } catch (e) {
            setLlmsStatus({ type: 'error', message: 'An error occurred.' });
        }
        setIsSavingLlms(false);
    };

    // Bulk Meta State
    const [bulkMeta, setBulkMeta] = useState({ items: [], total: 0, pages: 1, page: 1, post_types: [] });
    const [bulkMetaParams, setBulkMetaParams] = useState({ page: 1, post_type: 'post', search: '' });
    const [isLoadingBulkMeta, setIsLoadingBulkMeta] = useState(false);
    const [bulkMetaEdits, setBulkMetaEdits] = useState({});
    const [isSavingBulkMeta, setIsSavingBulkMeta] = useState(false);
    const [bulkMetaStatus, setBulkMetaStatus] = useState(null);

    const fetchBulkMeta = async () => {
        setIsLoadingBulkMeta(true);
        try {
            const params = new URLSearchParams(bulkMetaParams).toString();
            const r = await fetch(`${holvekAdminConfig.apiUrl}/bulk-meta?${params}`, {
                headers: { 'X-WP-Nonce': holvekAdminConfig.nonce }
            });
            if (r.ok) {
                const data = await r.json();
                setBulkMeta(data);
                setBulkMetaEdits({}); // clear unsaved edits on fetch
            }
        } catch (e) {}
        setIsLoadingBulkMeta(false);
    };

    const handleBulkMetaEdit = (id, field, value) => {
        setBulkMetaEdits(prev => ({
            ...prev,
            [id]: { ...prev[id], id, [field]: value }
        }));
    };

    const saveBulkMetaEdits = async () => {
        const updates = Object.values(bulkMetaEdits);
        if (updates.length === 0) return;
        setIsSavingBulkMeta(true);
        setBulkMetaStatus(null);
        try {
            const r = await fetch(`${holvekAdminConfig.apiUrl}/bulk-meta`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': holvekAdminConfig.nonce },
                body: JSON.stringify({ updates })
            });
            if (r.ok) {
                setBulkMetaStatus({ type: 'success', message: 'Meta updates saved!' });
                fetchBulkMeta();
            } else {
                setBulkMetaStatus({ type: 'error', message: 'Failed to save meta.' });
            }
        } catch (e) {
            setBulkMetaStatus({ type: 'error', message: 'An error occurred.' });
        }
        setIsSavingBulkMeta(false);
    };

    // Auto-fetch data on active tab transitions
    useEffect(() => {
        if (activeTab === 'compliance') { loadCookieBanner(); }
        if (activeTab === 'image_seo') { loadCompressionData(); loadAltAudit(1); }
        if (activeTab === 'roles') { loadRoles(); }
        if (activeTab === 'network_seo' && isMultisite) { loadNetworkData(); }
        if (activeTab === 'site_audit') { loadAuditResults(); }
        if (activeTab === 'broken_links') { loadBlcProgress(); loadBlcResults(1, false); }
        if (activeTab === 'rank_tracker') { loadRankData(); }

        if (activeTab === 'sitemaps') {
            fetchSitemapsSettings();
        }
        if (activeTab === 'schema') {
            fetchSchemaRules();
        }
        if (activeTab === 'social') {
            fetchSocialAccounts();
        }
        if (activeTab === 'llms') {
            fetchLlmsSettings();
        }
        if (activeTab === 'bulk_meta') {
            fetchBulkMeta();
        }
        if (activeTab === 'technical') {
            if (techSubTab === 'redirects') {
                fetchRedirects();
            } else if (techSubTab === '404s') {
                fetch404Logs();
            }
        }
    }, [activeTab, techSubTab, bulkMetaParams.page, bulkMetaParams.post_type]);

    const navItems = [
        { id: 'dashboard', label: __('Dashboard', 'holvek') },
        { id: 'analytics', label: __('Google Analytics 4', 'holvek') },
        { id: 'links', label: __('Link Health', 'holvek') },
        { id: 'settings', label: __('Settings & Integrations', 'holvek') },
        { id: 'technical', label: __('Technical SEO', 'holvek') },
        { id: 'sitemaps', label: __('XML Sitemaps', 'holvek') },
        { id: 'schema', label: __('Schema Markup', 'holvek') },
        { id: 'social', label: __('Social & Sharing', 'holvek') },
        { id: 'llms', label: __('LLM Agent Config', 'holvek') },
        { id: 'bulk_meta', label: __('Bulk Meta Editor', 'holvek') },
        { id: 'html_sitemap', label: __('HTML Sitemap', 'holvek') },
        { id: 'image_seo', label: __('Image SEO', 'holvek') },
        { id: 'compliance', label: __('Cookie & Compliance', 'holvek') },
        { id: 'roles', label: __('Roles & Capabilities', 'holvek') },
        { id: 'site_audit', label: __('Site Audit', 'holvek') },
        { id: 'broken_links', label: __('Broken Links', 'holvek') },
        { id: 'rank_tracker', label: __('Rank Tracker', 'holvek') },
    ];
    if (isMultisite) {
        navItems.push({ id: 'network_seo', label: __('Network SEO', 'holvek') });
    }
    const activeNav = navItems.find((item) => item.id === activeTab) || navItems[0];
    const onNavKeyDown = (event) => {
        if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
            return;
        }
        event.preventDefault();
        const index = navItems.findIndex((item) => item.id === activeTab);
        let next = index < 0 ? 0 : index;
        if (event.key === 'ArrowDown') {
            next = (index + 1) % navItems.length;
        } else if (event.key === 'ArrowUp') {
            next = (index - 1 + navItems.length) % navItems.length;
        } else if (event.key === 'Home') {
            next = 0;
        } else if (event.key === 'End') {
            next = navItems.length - 1;
        }
        const nextId = navItems[next].id;
        setActiveTab(nextId);
        window.requestAnimationFrame(() => {
            const button = document.getElementById(`holvek-tab-${nextId}`);
            if (button) {
                button.focus();
            }
        });
    };

    const summary = standalone?.summary || __('Holvek writes titles, meta descriptions, schema, the sitemap, and robots.txt on this site. Redirects and 404 logs stay on this site. A Holvek account is optional.', 'holvek');
    const nextMessage = setupFinished
        ? __('Your titles and sitemap are already active.', 'holvek')
        : (standalone?.next_step_message || __('Set up Holvek to choose a site type. Titles and the sitemap already work.', 'holvek'));

    return (
        <>
        <div className="holvek-wrapper mt-5 p-6 bg-white rounded-lg shadow-sm border border-gray-200">
            <header className="mb-8 border-b pb-4">
                <h1 className="text-2xl font-bold text-slate-900 m-0">Holvek</h1>
                <p className="text-slate-500 m-0 mt-1">{__('Technical SEO, structured data, sitemaps, redirects, and indexing tools for WordPress.', 'holvek')}</p>
            </header>

            <div className="flex gap-6">
                <aside className="w-64 flex-shrink-0">
                    <nav className="flex flex-col gap-1" role="tablist" aria-orientation="vertical" aria-label={__('Holvek settings', 'holvek')}>
                        {navItems.map((item) => (
                            <NavItem
                                key={item.id}
                                id={item.id}
                                label={item.label}
                                active={activeTab === item.id}
                                onClick={() => setActiveTab(item.id)}
                                onKeyDown={onNavKeyDown}
                            />
                        ))}
                    </nav>
                </aside>

                <main id="holvek-tabpanel" className="flex-1" role="tabpanel" aria-labelledby={`holvek-tab-${activeNav.id}`}>
                <p className="holvek-sr" role="status" aria-live="polite">{activeNav.label}</p>
                {/* ══════════════ GSC DASHBOARD ══════════════ */}
                    {activeTab === 'dashboard' && (
                        <div className="space-y-6">
                            <section className="space-y-4" aria-labelledby="holvek-site-heading">
                                <div>
                                    <h2 id="holvek-site-heading" className="text-xl font-semibold m-0 text-slate-800">{__('This site', 'holvek')}</h2>
                                    <p className="text-sm text-slate-600 mt-2 mb-0">{summary}</p>
                                </div>
                                <p className="text-sm text-slate-800 m-0">{nextMessage}</p>
                                {!setupFinished && (
                                    <button type="button" onClick={() => setShowSetup(true)} className="px-4 py-2 bg-brand-500 text-white rounded font-medium">
                                        {__('Set up Holvek', 'holvek')}
                                    </button>
                                )}
                                {showSetup && (
                                    <SetupWizard onComplete={() => {
                                        setShowSetup(false);
                                        setSetupFinished(true);
                                        window.location.hash = '';
                                    }} />
                                )}
                                {standaloneLoading && <p role="status" className="text-sm text-slate-500 m-0">{__('Loading the local results.', 'holvek')}</p>}
                                {standaloneError && <p role="alert" className="text-sm text-amber-800 m-0">{standaloneError}</p>}
                                {standalone && (
                                    <dl className="grid grid-cols-1 md:grid-cols-2 gap-4 m-0">
                                        <div className="border rounded-lg p-4">
                                            <dt className="text-sm font-medium text-slate-500">{__('Indexability', 'holvek')}</dt>
                                            <dd className="text-sm text-slate-900 mt-1 mb-0">{standalone.indexability}</dd>
                                        </div>
                                        <div className="border rounded-lg p-4">
                                            <dt className="text-sm font-medium text-slate-500">{__('Sitemap', 'holvek')}</dt>
                                            <dd className="text-sm mt-1 mb-0 break-all">
                                                <a href={standalone.sitemap_url} className="text-indigo-700 underline">{standalone.sitemap_url}</a>
                                            </dd>
                                        </div>
                                        <div className="border rounded-lg p-4">
                                            <dt className="text-sm font-medium text-slate-500">{__('Missing titles', 'holvek')}</dt>
                                            <dd className="text-sm text-slate-900 mt-1 mb-0">{standalone.missing_titles_label}</dd>
                                        </div>
                                        <div className="border rounded-lg p-4">
                                            <dt className="text-sm font-medium text-slate-500">{__('Missing alt text', 'holvek')}</dt>
                                            <dd className="text-sm text-slate-900 mt-1 mb-0">{standalone.missing_alt_label}</dd>
                                        </div>
                                        <div className="border rounded-lg p-4">
                                            <dt className="text-sm font-medium text-slate-500">{__('Redirects', 'holvek')}</dt>
                                            <dd className="text-sm text-slate-900 mt-1 mb-0">{standalone.redirects_label}</dd>
                                        </div>
                                        <div className="border rounded-lg p-4">
                                            <dt className="text-sm font-medium text-slate-500">{__('Not found', 'holvek')}</dt>
                                            <dd className="text-sm text-slate-900 mt-1 mb-0">{standalone.not_found_label}</dd>
                                        </div>
                                    </dl>
                                )}
                                <div className="border rounded-lg p-4 space-y-3">
                                    <h3 className="text-base font-semibold text-slate-800 m-0">{__('Optional next step', 'holvek')}</h3>
                                    <p className="text-sm text-slate-600 m-0">{__('Connect Google Search Console, or connect Holvek. Neither one is required.', 'holvek')}</p>
                                    <div className="flex flex-wrap gap-2">
                                        <button type="button" onClick={() => { setActiveTab('settings'); setFocusIndexing(true); }} className="px-4 py-2 bg-white border border-slate-300 rounded font-medium text-slate-800">
                                            {__('Connect Google Search Console', 'holvek')}
                                        </button>
                                        <button type="button" aria-expanded={showHolvekNote} aria-controls="holvek-connect-note" onClick={() => setShowHolvekNote(open => !open)} className="px-4 py-2 bg-white border border-slate-300 rounded font-medium text-slate-800">
                                            {__('Connect Holvek', 'holvek')}
                                        </button>
                                    </div>
                                    {showHolvekNote && (
                                        <div id="holvek-connect-note" className="space-y-3">
                                            <p className="text-sm text-slate-700 m-0">{__('Connecting Holvek is optional. This site keeps titles, the sitemap, redirects, and 404 logs without an account.', 'holvek')}</p>
                                            <p id="holvek-connect-host" className="text-sm text-slate-700 m-0">{__('The settings screen cannot change the Holvek API host.', 'holvek')}</p>
                                            {canConnectHolvek && (
                                                <div className="space-y-3">
                                                    {connectionLoading && <p role="status" className="text-sm text-slate-500 m-0">{__('Loading the Holvek connection.', 'holvek')}</p>}
                                                    {connectionError && <p role="alert" className="text-sm text-amber-800 m-0">{connectionError}</p>}
                                                    {connectionMessage && <p role="status" className="text-sm text-slate-700 m-0">{connectionMessage}</p>}
                                                    {connection && connection.api_base && (
                                                        <p className="text-sm text-slate-700 m-0 break-all">{__('Holvek requests use this address:', 'holvek')} {connection.api_base}</p>
                                                    )}
                                                    {connection && connection.connected && <p className="text-sm text-slate-800 m-0">{__('This site is connected to Holvek.', 'holvek')}</p>}
                                                    {connection && !connection.connected && <p className="text-sm text-slate-700 m-0">{__('No Holvek credential is saved as connected on this site.', 'holvek')}</p>}
                                                    <div className="space-y-2">
                                                        <label htmlFor="holvek-site-token" className="block text-sm font-medium text-slate-800">{__('Site token', 'holvek')}</label>
                                                        <input id="holvek-site-token" type="password" autoComplete="off" spellCheck={false} aria-describedby="holvek-connect-host" value={siteToken} onChange={(event) => setSiteToken(event.target.value)} className="w-full border border-slate-200 rounded px-3 py-2 text-sm" />
                                                        <button type="button" disabled={connectionBusy || siteToken.trim() === ''} onClick={() => postConnection('/connection/site-token', { site_token: siteToken.trim() })} className="px-4 py-2 bg-white border border-slate-300 rounded font-medium text-slate-800 disabled:opacity-50">
                                                            {__('Connect with site token', 'holvek')}
                                                        </button>
                                                    </div>
                                                    <div className="space-y-2">
                                                        <label htmlFor="holvek-authorization-code" className="block text-sm font-medium text-slate-800">{__('Authorization code', 'holvek')}</label>
                                                        <input id="holvek-authorization-code" type="password" autoComplete="off" spellCheck={false} aria-describedby="holvek-connect-host" value={authorizationCode} onChange={(event) => setAuthorizationCode(event.target.value)} className="w-full border border-slate-200 rounded px-3 py-2 text-sm" />
                                                        <button type="button" disabled={connectionBusy || authorizationCode.trim() === ''} onClick={() => postConnection('/connection/authorization-code', { code: authorizationCode.trim() })} className="px-4 py-2 bg-white border border-slate-300 rounded font-medium text-slate-800 disabled:opacity-50">
                                                            {__('Connect with authorization code', 'holvek')}
                                                        </button>
                                                    </div>
                                                    {connection && connection.credential_saved && (
                                                        <div className="flex flex-wrap gap-2">
                                                            <button type="button" disabled={connectionBusy} onClick={() => postConnection('/connection/verify')} className="px-4 py-2 bg-white border border-slate-300 rounded font-medium text-slate-800 disabled:opacity-50">
                                                                {__('Check saved credential', 'holvek')}
                                                            </button>
                                                            <button type="button" disabled={connectionBusy} onClick={() => postConnection('/connection/rotate')} className="px-4 py-2 bg-white border border-slate-300 rounded font-medium text-slate-800 disabled:opacity-50">
                                                                {__('Rotate credential', 'holvek')}
                                                            </button>
                                                            <button type="button" disabled={connectionBusy} onClick={() => postConnection('/connection/revoke')} className="px-4 py-2 bg-white border border-slate-300 rounded font-medium text-slate-800 disabled:opacity-50">
                                                                {__('Disconnect', 'holvek')}
                                                            </button>
                                                        </div>
                                                    )}
                                                </div>
                                            )}
                                        </div>
                                    )}
                                </div>
                            </section>

                            <section className="space-y-4 border rounded-lg p-4" aria-labelledby="holvek-gsc-heading">
                            <div className="flex items-center justify-between">
                                <div>
                                    <h3 id="holvek-gsc-heading" className="text-base font-semibold m-0 text-slate-800">{__('Search Console', 'holvek')}</h3>
                                    <p className="text-sm text-slate-600 mt-1 mb-0">{__('Search Console is optional. Titles, the sitemap, and robots.txt are already published without it.', 'holvek')}</p>
                                </div>
                            </div>
                            {!gscOpen && (
                                <button type="button" onClick={() => setGscOpen(true)} className="px-4 py-2 bg-white border border-slate-300 rounded font-medium text-slate-800">
                                    {__('Show Search Console', 'holvek')}
                                </button>
                            )}
                            {gscOpen && (
                            <>
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm font-semibold m-0 text-slate-800">{__('Google Search Console', 'holvek')}</p>
                                    {gscData?.period && <p className="text-sm text-slate-500 mt-1 mb-0">{gscData.period.start} → {gscData.period.end} &nbsp;·&nbsp; {gscData.cached ? '⚡ Cached' : '🔄 Live'}</p>}
                                </div>
                                <button type="button" onClick={() => { setGscData(null); setCannData(null); }} className="text-xs text-indigo-600 hover:underline bg-transparent border-none cursor-pointer p-0">↺ Refresh</button>
                            </div>

                            {gscLoading && <div className="flex items-center gap-3 p-6 bg-slate-50 rounded-lg"><Spinner /><span className="text-slate-500">Connecting to Google Search Console…</span></div>}
                            {gscError && <div className="p-4 bg-amber-50 border border-amber-200 rounded-lg text-amber-800 text-sm">⚠ {gscError}</div>}

                            {gscData?.summary && (
                                <div className="grid grid-cols-4 gap-4">
                                    <StatCard title="Total Clicks" value={fmtNum(gscData.summary.clicks)} type="success" />
                                    <StatCard title="Impressions" value={fmtNum(gscData.summary.impressions)} />
                                    <StatCard title="Avg. CTR" value={gscData.summary.avg_ctr + '%'} />
                                    <StatCard title="Avg. Position" value={String(gscData.summary.avg_position)} type="success" />
                                </div>
                            )}

                            {gscData && (
                                <div className="flex gap-2 border-b border-slate-200">
                                    {[['overview','📋 Overview'],['pages','📄 Top Pages'],['cannibalization','⚠ Cannibalization']].map(([id, label]) => (
                                        <button type="button" key={id} onClick={() => setGscSubTab(id)}
                                            className={`px-4 py-2 text-sm font-medium border-b-2 transition-colors bg-transparent cursor-pointer ${gscSubTab === id ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-slate-500 hover:text-slate-800'}`}>{label}</button>
                                    ))}
                                </div>
                            )}

                            {gscData && gscSubTab === 'overview' && (
                                <div className="space-y-6">
                                    {gscData.trend?.length > 0 && (
                                        <div className="bg-white border rounded-lg p-4">
                                            <h3 className="text-sm font-semibold text-slate-700 mb-3 m-0">28-Day Click Trend</h3>
                                            <ResponsiveContainer width="100%" height={180}>
                                                <LineChart data={gscData.trend} margin={{ top: 4, right: 8, left: -20, bottom: 0 }}>
                                                    <XAxis dataKey="date" tick={{ fontSize: 10 }} tickFormatter={d => d.slice(5)} />
                                                    <YAxis tick={{ fontSize: 10 }} />
                                                    <Tooltip formatter={(v, n) => [fmtNum(v), n === 'clicks' ? 'Clicks' : 'Impressions']} />
                                                    <Line type="monotone" dataKey="clicks" stroke="#6366f1" strokeWidth={2} dot={false} name="clicks" />
                                                    <Line type="monotone" dataKey="impressions" stroke="#cbd5e1" strokeWidth={1} dot={false} name="impressions" />
                                                </LineChart>
                                            </ResponsiveContainer>
                                        </div>
                                    )}
                                    <div className="bg-white border rounded-lg overflow-hidden">
                                        <div className="px-4 py-3 border-b bg-slate-50"><span className="text-sm font-semibold text-slate-700">Top Queries (28 days)</span></div>
                                        <table className="w-full text-left border-collapse">
                                            <thead><tr className="bg-slate-50 border-b">
                                                <th className="p-3 text-xs font-semibold text-slate-500 uppercase">Query</th>
                                                <th className="p-3 text-xs font-semibold text-slate-500 uppercase text-right">Clicks</th>
                                                <th className="p-3 text-xs font-semibold text-slate-500 uppercase text-right">Impr.</th>
                                                <th className="p-3 text-xs font-semibold text-slate-500 uppercase text-right">CTR</th>
                                                <th className="p-3 text-xs font-semibold text-slate-500 uppercase text-right">Pos.</th>
                                            </tr></thead>
                                            <tbody>
                                                {(gscData.top_queries || []).map((kw, i) => (
                                                    <tr key={i} className="border-b last:border-0 hover:bg-slate-50">
                                                        <td className="p-3 text-sm text-slate-800">{kw.query}</td>
                                                        <td className="p-3 text-sm font-medium text-slate-700 text-right">{fmtNum(kw.clicks)}</td>
                                                        <td className="p-3 text-sm text-slate-500 text-right">{fmtNum(kw.impressions)}</td>
                                                        <td className="p-3 text-sm text-slate-500 text-right">{kw.ctr}%</td>
                                                        <td className="p-3 text-sm font-semibold text-right" style={{ color: posColor(kw.position) }}>{kw.position}</td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            )}

                            {gscData && gscSubTab === 'pages' && (
                                <div className="bg-white border rounded-lg overflow-hidden">
                                    <div className="px-4 py-3 border-b bg-slate-50"><span className="text-sm font-semibold text-slate-700">Top Pages by Clicks (28 days)</span></div>
                                    <table className="w-full text-left border-collapse">
                                        <thead><tr className="bg-slate-50 border-b">
                                            <th className="p-3 text-xs font-semibold text-slate-500 uppercase">Page</th>
                                            <th className="p-3 text-xs font-semibold text-slate-500 uppercase text-right">Clicks</th>
                                            <th className="p-3 text-xs font-semibold text-slate-500 uppercase text-right">Impr.</th>
                                            <th className="p-3 text-xs font-semibold text-slate-500 uppercase text-right">CTR</th>
                                            <th className="p-3 text-xs font-semibold text-slate-500 uppercase text-right">Pos.</th>
                                        </tr></thead>
                                        <tbody>
                                            {(gscData.top_pages || []).map((pg, i) => (
                                                <tr key={i} className="border-b last:border-0 hover:bg-slate-50">
                                                    <td className="p-3 text-sm">
                                                        <div className="font-medium text-slate-800">{pg.label}</div>
                                                        <div className="text-xs text-slate-400 truncate max-w-xs">{pg.url}</div>
                                                    </td>
                                                    <td className="p-3 text-sm font-medium text-slate-700 text-right">{fmtNum(pg.clicks)}</td>
                                                    <td className="p-3 text-sm text-slate-500 text-right">{fmtNum(pg.impressions)}</td>
                                                    <td className="p-3 text-sm text-slate-500 text-right">{pg.ctr}%</td>
                                                    <td className="p-3 text-sm font-semibold text-right" style={{ color: posColor(pg.position) }}>{pg.position}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}

                            {gscData && gscSubTab === 'cannibalization' && (
                                <div className="space-y-4">
                                    <div className="p-4 bg-amber-50 border border-amber-200 rounded-lg text-sm text-amber-800">Keyword cannibalization occurs when multiple pages compete for the same search query. Consolidate or differentiate these pages to improve rankings.</div>
                                    {cannLoading && <div className="flex items-center gap-3 p-6 bg-slate-50 rounded-lg"><Spinner /><span className="text-slate-500">Analysing 90-day GSC data…</span></div>}
                                    {cannData && cannData.conflicts.length === 0 && <div className="p-6 text-center text-green-700 bg-green-50 rounded-lg border border-green-200">✅ No cannibalization detected in the last 90 days.</div>}
                                    {(cannData?.conflicts || []).map((c, i) => (
                                        <div key={i} className="border rounded-lg overflow-hidden">
                                            <div className={`px-4 py-2 flex items-center gap-2 text-sm font-semibold ${c.severity === 'high' ? 'bg-red-50 text-red-800 border-b border-red-200' : 'bg-amber-50 text-amber-800 border-b border-amber-200'}`}>
                                                <span>{c.severity === 'high' ? '🔴' : '🟡'}</span>
                                                <span className="flex-1">"{c.query}"</span>
                                                <span className="text-xs font-normal opacity-75">{c.page_count} competing pages</span>
                                            </div>
                                            <table className="w-full text-left border-collapse"><tbody>
                                                {c.pages.map((p, j) => (
                                                    <tr key={j} className="border-b last:border-0 hover:bg-slate-50">
                                                        <td className="p-3 text-sm"><div className="font-medium text-slate-800">{p.title}</div><div className="text-xs text-slate-400">{p.url}</div></td>
                                                        <td className="p-3 text-sm text-slate-600 text-right">{p.clicks} clicks</td>
                                                        <td className="p-3 text-sm font-semibold text-right" style={{ color: posColor(p.position) }}>#{p.position}</td>
                                                        {p.post_id && <td className="p-3"><a href={`/wp-admin/post.php?post=${p.post_id}&action=edit`} className="text-xs text-indigo-600 hover:underline">Edit →</a></td>}
                                                    </tr>
                                                ))}
                                            </tbody></table>
                                        </div>
                                    ))}
                                </div>
                            )}
                            </>
                            )}
                            </section>
                        </div>
                    )}

                    {/* ══════════════ GA4 ANALYTICS TAB ══════════════ */}
                    {activeTab === 'analytics' && (
                        <div className="space-y-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <h2 className="text-xl font-semibold m-0 text-slate-800">Google Analytics 4</h2>
                                    {ga4Report?.period && <p className="text-sm text-slate-500 mt-1 mb-0">{ga4Report.period.start} → {ga4Report.period.end} &nbsp;·&nbsp; {ga4Report.cached ? '⚡ Cached' : '🔄 Live'}</p>}
                                </div>
                                <button type="button" onClick={() => { setGa4Report(null); setGa4Error(null); }} className="text-xs text-indigo-600 hover:underline bg-transparent border-none cursor-pointer p-0">↺ Refresh</button>
                            </div>

                            {ga4Loading && <div className="flex items-center gap-3 p-6 bg-slate-50 rounded-lg"><Spinner /><span className="text-slate-500">Connecting to Google Analytics 4…</span></div>}

                            {ga4Error && (
                                <div className="bg-white border rounded-lg p-6 space-y-4">
                                    <div className="p-4 bg-amber-50 border border-amber-200 rounded-lg text-amber-800 text-sm">⚠ {ga4Error}</div>
                                    <h3 className="text-base font-semibold text-slate-800 m-0">Connect Google Analytics 4</h3>
                                    <p className="text-sm text-slate-500 m-0">Enter your GA4 Measurement ID and Property ID, then paste your Service Account JSON credentials.</p>
                                    <div className="grid grid-cols-2 gap-4">
                                        <div>
                                            <label className="block text-xs font-semibold text-slate-600 mb-1">Measurement ID <span className="font-normal text-slate-400">(G-XXXXXXXXXX)</span></label>
                                            <input type="text" placeholder="G-XXXXXXXXXX" value={ga4Settings.measurement_id} onChange={e => setGa4Settings(s => ({ ...s, measurement_id: e.target.value }))} className="w-full border border-slate-200 rounded px-3 py-2 text-sm" />
                                        </div>
                                        <div>
                                            <label className="block text-xs font-semibold text-slate-600 mb-1">Property ID <span className="font-normal text-slate-400">(numeric)</span></label>
                                            <input type="text" placeholder="123456789" value={ga4Settings.property_id} onChange={e => setGa4Settings(s => ({ ...s, property_id: e.target.value }))} className="w-full border border-slate-200 rounded px-3 py-2 text-sm" />
                                        </div>
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-slate-600 mb-1">Service Account JSON {ga4Settings.credentials_set && <span className="text-green-600 font-normal">(✓ saved)</span>}</label>
                                        <textarea rows={4} placeholder={'{ "type": "service_account", "client_email": "...", "private_key": "..." }'} value={ga4Settings.credentials} onChange={e => setGa4Settings(s => ({ ...s, credentials: e.target.value }))} className="w-full border border-slate-200 rounded px-3 py-2 text-sm font-mono" />
                                    </div>
                                    {ga4SaveMsg && <div className={`text-sm p-3 rounded ${ga4SaveMsg.type === 'success' ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800'}`}>{ga4SaveMsg.text}</div>}
                                    <button type="button" onClick={saveGa4Settings} disabled={ga4Saving} className="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded hover:bg-indigo-700 disabled:opacity-50">{ga4Saving ? 'Saving…' : 'Save & Connect'}</button>
                                </div>
                            )}

                            {ga4Report?.summary && (
                                <>
                                    <div className="grid grid-cols-5 gap-3">
                                        {[
                                            ['Sessions', fmtNum(ga4Report.summary.sessions), 'success'],
                                            ['Users', fmtNum(ga4Report.summary.users), 'default'],
                                            ['Pageviews', fmtNum(ga4Report.summary.pageviews), 'default'],
                                            ['Bounce Rate', ga4Report.summary.bounce_rate + '%', 'default'],
                                            ['Avg. Session', Math.floor(ga4Report.summary.avg_session / 60) + 'm ' + (ga4Report.summary.avg_session % 60) + 's', 'success'],
                                        ].map(([t, v, type]) => <StatCard key={t} title={t} value={v} type={type} />)}
                                    </div>

                                    {ga4Report.trend?.length > 0 && (
                                        <div className="bg-white border rounded-lg p-4">
                                            <h3 className="text-sm font-semibold text-slate-700 mb-3 m-0">28-Day Session Trend</h3>
                                            <ResponsiveContainer width="100%" height={180}>
                                                <LineChart data={ga4Report.trend} margin={{ top: 4, right: 8, left: -20, bottom: 0 }}>
                                                    <XAxis dataKey="date" tick={{ fontSize: 10 }} tickFormatter={d => d.slice(5)} />
                                                    <YAxis tick={{ fontSize: 10 }} />
                                                    <Tooltip />
                                                    <Line type="monotone" dataKey="sessions" stroke="#6366f1" strokeWidth={2} dot={false} name="Sessions" />
                                                    <Line type="monotone" dataKey="pageviews" stroke="#22c55e" strokeWidth={1} dot={false} name="Pageviews" />
                                                </LineChart>
                                            </ResponsiveContainer>
                                        </div>
                                    )}

                                    <div className="grid grid-cols-2 gap-6">
                                        <div className="bg-white border rounded-lg overflow-hidden">
                                            <div className="px-4 py-3 border-b bg-slate-50 text-sm font-semibold text-slate-700">Top Pages</div>
                                            <table className="w-full text-left"><tbody>
                                                {(ga4Report.top_pages || []).map((p, i) => (
                                                    <tr key={i} className="border-b last:border-0 hover:bg-slate-50">
                                                        <td className="p-3 text-sm"><div className="font-medium text-slate-700 truncate max-w-xs" title={p.title}>{p.title || p.path}</div><div className="text-xs text-slate-400">{p.path}</div></td>
                                                        <td className="p-3 text-sm text-right text-slate-600 whitespace-nowrap">{fmtNum(p.pageviews)} pvs</td>
                                                    </tr>
                                                ))}
                                            </tbody></table>
                                        </div>

                                        {ga4Report.channels?.length > 0 && (
                                            <div className="bg-white border rounded-lg p-4">
                                                <div className="text-sm font-semibold text-slate-700 mb-3">Traffic Channels</div>
                                                <ResponsiveContainer width="100%" height={200}>
                                                    <PieChart>
                                                        <Pie data={ga4Report.channels} dataKey="sessions" nameKey="channel" cx="50%" cy="50%" outerRadius={80} label={({ channel, percent }) => `${channel} ${(percent * 100).toFixed(0)}%`} labelLine={false}>
                                                            {ga4Report.channels.map((_, i) => <Cell key={i} fill={CHART_COLORS[i % CHART_COLORS.length]} />)}
                                                        </Pie>
                                                        <Tooltip />
                                                    </PieChart>
                                                </ResponsiveContainer>
                                            </div>
                                        )}
                                    </div>

                                    <details className="bg-slate-50 border rounded-lg">
                                        <summary className="px-4 py-3 text-sm font-medium text-slate-600 cursor-pointer">⚙ GA4 Connection Settings</summary>
                                        <div className="px-4 pb-4 space-y-3">
                                            <div className="grid grid-cols-2 gap-4 mt-3">
                                                <div>
                                                    <label className="block text-xs font-semibold text-slate-600 mb-1">Measurement ID</label>
                                                    <input type="text" value={ga4Settings.measurement_id} onChange={e => setGa4Settings(s => ({ ...s, measurement_id: e.target.value }))} className="w-full border border-slate-200 rounded px-3 py-2 text-sm" />
                                                </div>
                                                <div>
                                                    <label className="block text-xs font-semibold text-slate-600 mb-1">Property ID</label>
                                                    <input type="text" value={ga4Settings.property_id} onChange={e => setGa4Settings(s => ({ ...s, property_id: e.target.value }))} className="w-full border border-slate-200 rounded px-3 py-2 text-sm" />
                                                </div>
                                            </div>
                                            <div>
                                                <label className="block text-xs font-semibold text-slate-600 mb-1">Service Account JSON {ga4Settings.credentials_set && <span className="text-green-600 font-normal">(✓ saved)</span>}</label>
                                                <textarea rows={3} placeholder="Paste new credentials to replace…" value={ga4Settings.credentials} onChange={e => setGa4Settings(s => ({ ...s, credentials: e.target.value }))} className="w-full border border-slate-200 rounded px-3 py-2 text-sm font-mono" />
                                            </div>
                                            {ga4SaveMsg && <div className={`text-sm p-3 rounded ${ga4SaveMsg.type === 'success' ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800'}`}>{ga4SaveMsg.text}</div>}
                                            <button type="button" onClick={saveGa4Settings} disabled={ga4Saving} className="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded hover:bg-indigo-700 disabled:opacity-50">{ga4Saving ? 'Saving…' : 'Save Changes'}</button>
                                        </div>
                                    </details>
                                </>
                            )}
                        </div>
                    )}

                    {/* ══════════════ LINK HEALTH TAB ══════════════ */}
                    {activeTab === 'links' && (
                        <div className="space-y-6">
                            <h2 className="text-xl font-semibold m-0 text-slate-800">Link Health</h2>

                            <div className="flex gap-2 border-b border-slate-200">
                                {[['orphans','🏝 Orphan Pages'],['stats','📊 Link Statistics']].map(([id, label]) => (
                                    <button type="button" key={id} onClick={() => setLinksSubTab(id)}
                                        className={`px-4 py-2 text-sm font-medium border-b-2 transition-colors bg-transparent cursor-pointer ${linksSubTab === id ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-slate-500 hover:text-slate-800'}`}>{label}</button>
                                ))}
                            </div>

                            {linksSubTab === 'orphans' && (
                                <div className="space-y-4">
                                    {orphanLoading && <div className="flex items-center gap-3 p-6 bg-slate-50 rounded-lg"><Spinner /><span className="text-slate-500">Scanning internal link index…</span></div>}
                                    {orphanData && (
                                        <>
                                            <div className="grid grid-cols-3 gap-4">
                                                <StatCard title="Total Posts/Pages" value={String(orphanData.total)} />
                                                <StatCard title="Orphan Pages" value={String(orphanData.orphan_count)} type={orphanData.orphan_count > 0 ? 'default' : 'success'} />
                                                <StatCard title="Linked Pages" value={String(orphanData.linked_count)} type="success" />
                                            </div>
                                            {orphanData.orphan_count === 0 ? (
                                                <div className="p-6 text-center text-green-700 bg-green-50 rounded-lg border border-green-200">✅ No orphan pages detected. Every page has at least one incoming internal link.</div>
                                            ) : (
                                                <div className="bg-white border rounded-lg overflow-hidden">
                                                    <div className="px-4 py-3 border-b bg-slate-50 flex items-center justify-between">
                                                        <span className="text-sm font-semibold text-slate-700">Orphan Pages — No Incoming Internal Links</span>
                                                        <span className="text-xs text-slate-400">{orphanData.orphan_count} pages need links</span>
                                                    </div>
                                                    <table className="w-full text-left border-collapse">
                                                        <thead><tr className="bg-slate-50 border-b">
                                                            <th className="p-3 text-xs font-semibold text-slate-500 uppercase">Page</th>
                                                            <th className="p-3 text-xs font-semibold text-slate-500 uppercase">Type</th>
                                                            <th className="p-3 text-xs font-semibold text-slate-500 uppercase">Modified</th>
                                                            <th className="p-3 text-xs font-semibold text-slate-500 uppercase text-right">Actions</th>
                                                        </tr></thead>
                                                        <tbody>
                                                            {orphanData.orphans.map((p, i) => (
                                                                <tr key={i} className="border-b last:border-0 hover:bg-slate-50">
                                                                    <td className="p-3"><div className="text-sm font-medium text-slate-800">{p.title}</div><div className="text-xs text-slate-400 truncate max-w-sm">{p.url}</div></td>
                                                                    <td className="p-3"><span className="text-xs bg-slate-100 text-slate-600 px-2 py-0.5 rounded">{p.post_type}</span></td>
                                                                    <td className="p-3 text-xs text-slate-500">{p.modified}</td>
                                                                    <td className="p-3 text-right">{p.edit_url && <a href={p.edit_url} target="_blank" rel="noreferrer" className="text-xs text-indigo-600 hover:underline">Edit →</a>}</td>
                                                                </tr>
                                                            ))}
                                                        </tbody>
                                                    </table>
                                                </div>
                                            )}
                                            {orphanData.total_pages > 1 && (
                                                <div className="flex items-center justify-between">
                                                    <button type="button" className="px-3 py-1 text-sm border rounded disabled:opacity-40" disabled={(orphanData.page || 1) <= 1} onClick={() => loadOrphans((orphanData.page || 1) - 1)}>Previous page</button>
                                                    <span className="text-xs text-slate-500">Page {orphanData.page || 1} of {orphanData.total_pages}</span>
                                                    <button type="button" className="px-3 py-1 text-sm border rounded disabled:opacity-40" disabled={(orphanData.page || 1) >= orphanData.total_pages} onClick={() => loadOrphans((orphanData.page || 1) + 1)}>Next page</button>
                                                </div>
                                            )}
                                        </>
                                    )}
                                </div>
                            )}

                            {linksSubTab === 'stats' && (
                                <div className="space-y-6">
                                    {linkStatsLoading && <div className="flex items-center gap-3 p-6 bg-slate-50 rounded-lg"><Spinner /><span className="text-slate-500">Loading link statistics…</span></div>}
                                    {linkStats?.message && <div className="p-4 bg-slate-50 border rounded-lg text-sm text-slate-600">ℹ {linkStats.message}</div>}
                                    {linkStats && linkStats.total_links !== undefined && (
                                        <>
                                            <StatCard title="Total Indexed Internal Links" value={fmtNum(linkStats.total_links)} type="success" />
                                            <div className="grid grid-cols-2 gap-6">
                                                <div className="bg-white border rounded-lg overflow-hidden">
                                                    <div className="px-4 py-3 border-b bg-slate-50 text-sm font-semibold text-slate-700">Most Linked-To Pages</div>
                                                    <table className="w-full text-left"><tbody>
                                                        {(linkStats.top_targets || []).map((t, i) => (
                                                            <tr key={i} className="border-b last:border-0 hover:bg-slate-50">
                                                                <td className="p-3 text-sm"><div className="font-medium text-slate-700 truncate max-w-xs">{t.title}</div><div className="text-xs text-slate-400">{t.target_url}</div></td>
                                                                <td className="p-3 text-sm text-right text-indigo-600 font-semibold whitespace-nowrap">{t.link_count} links</td>
                                                            </tr>
                                                        ))}
                                                    </tbody></table>
                                                </div>
                                                <div className="bg-white border rounded-lg overflow-hidden">
                                                    <div className="px-4 py-3 border-b bg-slate-50 text-sm font-semibold text-slate-700">Pages With Most Outgoing Links</div>
                                                    <table className="w-full text-left"><tbody>
                                                        {(linkStats.top_sources || []).map((s, i) => (
                                                            <tr key={i} className="border-b last:border-0 hover:bg-slate-50">
                                                                <td className="p-3 text-sm"><div className="font-medium text-slate-700 truncate max-w-xs">{s.title}</div></td>
                                                                <td className="p-3 text-sm text-right text-green-600 font-semibold whitespace-nowrap">{s.link_count} links</td>
                                                            </tr>
                                                        ))}
                                                    </tbody></table>
                                                </div>
                                            </div>
                                        </>
                                    )}
                                </div>
                            )}
                        </div>
                    )}


                    {activeTab === 'settings' && (
                        <div className="space-y-6 max-w-3xl">
                            <h2 className="text-xl font-semibold m-0 text-slate-800">Global Settings & Integrations</h2>
                            <p className="text-slate-600">Configure search engine APIs, webmaster verification codes, and site-wide breadcrumb preferences below.</p>
                            {isLoadingSettings ? (
                                <div className="p-8 text-center"><Spinner /></div>
                            ) : (
                                <div className="bg-slate-50 p-6 rounded-lg border border-slate-200 space-y-6">
                                    {saveStatus && (
                                        <Notice status={saveStatus.type} isDismissible={true} onRemove={() => setSaveStatus(null)}>
                                            {saveStatus.message}
                                        </Notice>
                                    )}

                                    {/* ── SECTION 1: Search Engine APIs ── */}
                                    <div id="holvek-search-engine-notifications" tabIndex={-1}>
                                        <h3 className="text-lg font-semibold text-slate-800 mb-2">Search Engine Notifications</h3>
                                        <p className="text-sm text-slate-500 mb-4">IndexNow can notify participating search engines of published URLs. Google Indexing API access is restricted to eligible JobPosting and livestream pages and never guarantees indexing.</p>
                                        
                                        <div className="space-y-4">
                                            <div>
                                                <label className="block text-xs font-semibold text-slate-700 mb-1">Google Indexing Service Account JSON</label>
                                                <TextareaControl value={settings.google_indexing_key} onChange={(val) => setSettings({ ...settings, google_indexing_key: val })} rows={6} placeholder='{"type": "service_account", ...}' />
                                                {settings.google_indexing_configured && <p className="text-xs text-emerald-700 mt-1 mb-0">A Google credential is saved. Leave this field empty to retain it.</p>}
                                            </div>
                                            <div>
                                                <label className="block text-xs font-semibold text-slate-700 mb-1">Bing IndexNow API Key</label>
                                                <TextControl value={settings.indexnow_key} onChange={(val) => setSettings({ ...settings, indexnow_key: val })} placeholder="e.g. 1a2b3c4d5e6f7g8h9i0j" />
                                                {settings.indexnow_configured && <p className="text-xs text-emerald-700 mt-1 mb-0">An IndexNow key is saved. Leave this field empty to retain it.</p>}
                                            </div>
                                            <ToggleControl label="Enable automatic IndexNow notifications" help="Send published URLs to IndexNow-participating search engines. This does not guarantee indexing." checked={settings.auto_index} onChange={(val) => setSettings({ ...settings, auto_index: val })} />
                                            <ToggleControl label="Enable Google Indexing API for eligible content" help="Only JobPosting and livestream pages are eligible. A service-account credential is required; this does not guarantee indexing." checked={settings.enable_google_indexing_api} onChange={(val) => setSettings({ ...settings, enable_google_indexing_api: val })} />
                                        </div>
                                    </div>

                                    {/* ── SECTION 2: Webmaster Tools Verification ── */}
                                    <div className="pt-6 border-t border-slate-200">
                                        <h3 className="text-lg font-semibold text-slate-800 mb-2">Webmaster Tools Verification</h3>
                                        <p className="text-sm text-slate-500 mb-4">Paste the verification code (usually found in the content="..." attribute of the meta tag) to verify your domain.</p>
                                        
                                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                            <TextControl
                                                label="Google Search Console verification code"
                                                value={settings.google_verify}
                                                onChange={(val) => setSettings({ ...settings, google_verify: val })}
                                                placeholder="e.g. AbC123xyz..."
                                            />
                                            <TextControl
                                                label="Bing Webmaster tools verification code"
                                                value={settings.bing_verify}
                                                onChange={(val) => setSettings({ ...settings, bing_verify: val })}
                                                placeholder="e.g. 1234567890ABCDEF..."
                                            />
                                            <TextControl
                                                label="Yandex Webmaster verification code"
                                                value={settings.yandex_verify}
                                                onChange={(val) => setSettings({ ...settings, yandex_verify: val })}
                                                placeholder="e.g. a1b2c3d4e5f6..."
                                            />
                                            <TextControl
                                                label="Pinterest verification code"
                                                value={settings.pinterest_verify}
                                                onChange={(val) => setSettings({ ...settings, pinterest_verify: val })}
                                                placeholder="e.g. 0987654321fedcba..."
                                            />
                                        </div>
                                    </div>

                                    {/* ── SECTION 3: Breadcrumbs Navigation ── */}
                                    <div className="pt-6 border-t border-slate-200">
                                        <h3 className="text-lg font-semibold text-slate-800 mb-2">Breadcrumb Navigation</h3>
                                        <p className="text-sm text-slate-500 mb-4">Set up schema-compliant breadcrumbs with automated insertion rules.</p>
                                        
                                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                            <TextControl
                                                label="Home Label"
                                                value={settings.breadcrumb_home_label}
                                                onChange={(val) => setSettings({ ...settings, breadcrumb_home_label: val })}
                                            />
                                            <TextControl
                                                label="Breadcrumb Separator Symbol"
                                                value={settings.breadcrumb_separator}
                                                onChange={(val) => setSettings({ ...settings, breadcrumb_separator: val })}
                                            />
                                        </div>

                                        <div style={{ marginBottom: '16px' }}>
                                            <label className="block text-xs font-semibold text-slate-700 mb-2">Theme Insertion Mode</label>
                                            <select
                                                value={settings.breadcrumb_auto_insert}
                                                onChange={(e) => setSettings({ ...settings, breadcrumb_auto_insert: e.target.value })}
                                                style={{ width: '100%', height: '36px', padding: '0 8px', border: '1px solid #cbd5e1', borderRadius: '4px', background: '#fff' }}
                                            >
                                                <option value="none">Manual insertion (PHP callback / shortcode only)</option>
                                                <option value="before_content">Automatically prepend before post content block</option>
                                            </select>
                                        </div>
                                        <p className="text-xs text-slate-400">
                                            You can manually place breadcrumbs anywhere in your template files with: <code>&lt;?php holvek_breadcrumbs(); ?&gt;</code> or shortcode: <code>[holvek_breadcrumbs]</code>.
                                        </p>
                                    </div>

                                    {/* ── SECTION 4: AI Integration Vault ── */}
                                    <div className="pt-6 border-t border-slate-200">
                                        <h3 className="text-lg font-semibold text-slate-800 mb-2">AI Integration Vault</h3>
                                        <p className="text-sm text-slate-500 mb-4">{__('The writing assistant uses an OpenAI, Anthropic, or Ollama key saved on this site.', 'holvek')}</p>
                                        
                                        {aiTestResult && (
                                            <Notice status={aiTestResult.type} isDismissible={true} onRemove={() => setAiTestResult(null)} className="mb-4">
                                                {aiTestResult.message}
                                            </Notice>
                                        )}

                                        <div style={{ marginBottom: '16px' }}>
                                            <label className="block text-xs font-semibold text-slate-700 mb-2">Active AI Provider</label>
                                            <select
                                                value={settings.ai_provider}
                                                onChange={(e) => setSettings({ ...settings, ai_provider: e.target.value })}
                                                style={{ width: '100%', height: '36px', padding: '0 8px', border: '1px solid #cbd5e1', borderRadius: '4px', background: '#fff' }}
                                            >
                                                <option value="openai">OpenAI (GPT-4o-mini)</option>
                                                <option value="anthropic">Anthropic (Claude 3.5 Sonnet)</option>
                                                <option value="ollama">Ollama (Local Instance)</option>
                                            </select>
                                        </div>

                                        {settings.ai_provider === 'openai' && (
                                            <div style={{ marginBottom: '16px' }}>
                                                <TextControl
                                                    label="OpenAI API Key"
                                                    value={settings.openai_key}
                                                    type="password"
                                                    onChange={(val) => { setSettings({ ...settings, openai_key: val }); setFormErrors(e => ({ ...e, openai_key: '' })); }}
                                                    placeholder="sk-..."
                                                    className={formErrors.openai_key ? 'holvek-field-error' : ''}
                                                />
                                                {formErrors.openai_key && <p role="alert" style={{ color: '#991b1b', fontSize: '12px', margin: '-8px 0 8px' }}>{formErrors.openai_key}</p>}
                                            </div>
                                        )}

                                        {settings.ai_provider === 'anthropic' && (
                                            <div style={{ marginBottom: '16px' }}>
                                                <TextControl
                                                    label="Anthropic API Key"
                                                    value={settings.anthropic_key}
                                                    type="password"
                                                    onChange={(val) => { setSettings({ ...settings, anthropic_key: val }); setFormErrors(e => ({ ...e, anthropic_key: '' })); }}
                                                    placeholder="sk-ant-..."
                                                    className={formErrors.anthropic_key ? 'holvek-field-error' : ''}
                                                />
                                                {formErrors.anthropic_key && <p role="alert" style={{ color: '#991b1b', fontSize: '12px', margin: '-8px 0 8px' }}>{formErrors.anthropic_key}</p>}
                                            </div>
                                        )}

                                        {settings.ai_provider === 'ollama' && (
                                            <div style={{ marginBottom: '16px' }}>
                                                <TextControl
                                                    label="Ollama Host URL"
                                                    value={settings.ollama_url}
                                                    onChange={(val) => { setSettings({ ...settings, ollama_url: val, ollama_remote_confirmed: ollamaHostIsLoopback(val) ? false : settings.ollama_remote_confirmed }); setFormErrors(e => ({ ...e, ollama_url: '' })); }}
                                                    placeholder="http://localhost:11434"
                                                    aria-describedby="holvek-ollama-help"
                                                    className={formErrors.ollama_url ? 'holvek-field-error' : ''}
                                                />
                                                <p id="holvek-ollama-help" style={{ color: '#64748b', fontSize: '12px', margin: '0 0 8px' }}>
                                                    Ollama stays on this server at http://localhost:11434 unless you choose another host. A remote host receives the post title and content you send for suggestions.
                                                </p>
                                                {settings.ollama_url.trim() !== '' && !ollamaHostIsLoopback(settings.ollama_url) && (
                                                    <label htmlFor="holvek-ollama-remote" style={{ display: 'flex', gap: '8px', alignItems: 'flex-start', fontSize: '13px', marginBottom: '8px' }}>
                                                        <input
                                                            id="holvek-ollama-remote"
                                                            type="checkbox"
                                                            checked={!!settings.ollama_remote_confirmed}
                                                            onChange={(event) => setSettings({ ...settings, ollama_remote_confirmed: event.target.checked })}
                                                        />
                                                        <span>Send post content to this remote Ollama host.</span>
                                                    </label>
                                                )}
                                                {formErrors.ollama_url && <p role="alert" style={{ color: '#991b1b', fontSize: '12px', margin: '-8px 0 8px' }}>{formErrors.ollama_url}</p>}
                                            </div>
                                        )}

                                        <div style={{ marginTop: '12px' }}>
                                            <Button isSecondary isBusy={isTestingAi} onClick={testAiConnection}>
                                                {isTestingAi ? 'Testing Connection...' : 'Test AI Connection'}
                                            </Button>
                                        </div>
                                    </div>

                                    {/* ── SECTION 5: Global Fallback Share Image ── */}
                                    <div className="pt-6 border-t border-slate-200">
                                        <h3 className="text-lg font-semibold text-slate-800 mb-2">Global Fallback Share Image</h3>
                                        <p className="text-sm text-slate-500 mb-4">Specify a default social sharing image to be used when a post lacks custom featured media or local OG overrides.</p>
                                        
                                        <div style={{ display: 'flex', gap: '10px', alignItems: 'end' }}>
                                            <div style={{ flex: 1 }}>
                                                <TextControl
                                                    label="Fallback Image URL"
                                                    value={settings.default_share_image}
                                                    onChange={(val) => setSettings({ ...settings, default_share_image: val })}
                                                    placeholder="https://example.com/wp-content/uploads/default-og.jpg"
                                                />
                                            </div>
                                            {window.wp && window.wp.media && (
                                                <div style={{ marginBottom: '8px' }}>
                                                    <Button isSecondary onClick={openMediaLibrary}>Select Image</Button>
                                                </div>
                                            )}
                                        </div>
                                        {settings.default_share_image && (
                                            <div style={{ marginTop: '12px', maxWidth: '300px', border: '1px solid #cbd5e1', borderRadius: '6px', overflow: 'hidden', background: '#f8fafc', padding: '6px' }}>
                                                <img src={settings.default_share_image} alt="Fallback Share Preview" style={{ width: '100%', height: 'auto', display: 'block', borderRadius: '4px' }} />
                                            </div>
                                        )}
                                    </div>

                                    <div className="pt-4 border-t border-slate-200">
                                        <h3 className="text-lg font-semibold text-slate-800 mb-2">Uninstall data</h3>
                                        <ToggleControl label="Delete Holvek data when the plugin is deleted" help="Disabled by default. Enable only if you want plugin settings, SEO metadata, logs, and plugin tables permanently removed on uninstall." checked={settings.delete_data_on_uninstall} onChange={(val) => setSettings({ ...settings, delete_data_on_uninstall: val })} />
                                    </div>

                                    <div className="pt-4 border-t border-slate-200">
                                        <Button isPrimary isBusy={isSaving} onClick={saveSettings}>
                                            {isSaving ? 'Saving Settings...' : 'Save Settings'}
                                        </Button>
                                    </div>
                                </div>
                            )}

                            {/* ── Import / Export ── */}
                            <div className="bg-white border border-slate-200 rounded-lg p-6 space-y-5">
                                <div>
                                    <h3 className="text-base font-semibold text-slate-800 m-0">Import / Export Settings</h3>
                                    <p className="text-sm text-slate-500 mt-1 mb-0">Back up your configuration or migrate settings between sites. API keys and private credentials are excluded from import for security.</p>
                                </div>

                                <div className="grid grid-cols-2 gap-6">
                                    {/* Export */}
                                    <div className="space-y-3">
                                        <h4 className="text-sm font-semibold text-slate-700 m-0">Export</h4>
                                        <p className="text-xs text-slate-500 m-0">Download all Holvek settings as a JSON file. Save it as a backup or use it to clone settings to another site.</p>
                                        {exportMsg && <div className={`text-xs px-3 py-2 rounded ${exportMsg.type === 'success' ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800'}`}>{exportMsg.text}</div>}
                                        <button type="button" onClick={downloadExport} className="flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded hover:bg-indigo-700">
                                            ⬇ Download Settings JSON
                                        </button>
                                    </div>

                                    {/* Import */}
                                    <div className="space-y-3">
                                        <h4 className="text-sm font-semibold text-slate-700 m-0">Import</h4>
                                        <p className="text-xs text-slate-500 m-0">Upload a previously exported Holvek settings file to restore or copy configuration. Existing API keys will not be overwritten.</p>
                                        {importMsg && <div className={`text-xs px-3 py-2 rounded ${importMsg.type === 'success' ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800'}`}>{importMsg.text}</div>}
                                        <label className={`flex items-center gap-2 px-4 py-2 text-sm font-medium rounded cursor-pointer border-2 border-dashed border-slate-300 hover:border-indigo-400 text-slate-600 hover:text-indigo-600 transition-colors ${importBusy ? 'opacity-50 pointer-events-none' : ''}`}>
                                            <input type="file" accept=".json,application/json" className="hidden" onChange={e => handleImportFile(e.target.files?.[0])} />
                                            {importBusy ? '⏳ Importing…' : '⬆ Choose JSON File'}
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                    {activeTab === 'technical' && (
                        <div className="space-y-6 max-w-4xl">
                            <div className="flex justify-between items-center border-b pb-4">
                                <h2 className="text-xl font-semibold m-0 text-slate-800">Technical SEO</h2>
                                <div className="flex bg-slate-100 p-1 rounded-lg border border-slate-200">
                                    <button type="button"
                                        onClick={() => setTechSubTab('robots')}
                                        className={`px-3 py-1.5 rounded-md font-medium text-xs transition-all ${techSubTab === 'robots' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-800'}`}
                                    >
                                        robots.txt
                                    </button>
                                    <button type="button"
                                        onClick={() => setTechSubTab('redirects')}
                                        className={`px-3 py-1.5 rounded-md font-medium text-xs transition-all ${techSubTab === 'redirects' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-800'}`}
                                    >
                                        Redirects Manager
                                    </button>
                                    <button type="button"
                                        onClick={() => setTechSubTab('404s')}
                                        className={`px-3 py-1.5 rounded-md font-medium text-xs transition-all ${techSubTab === '404s' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-800'}`}
                                    >
                                        404 Error Monitor
                                    </button>
                                    <button type="button"
                                        onClick={() => setTechSubTab('htaccess')}
                                        className={`px-3 py-1.5 rounded-md font-medium text-xs transition-all ${techSubTab === 'htaccess' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-800'}`}
                                    >
                                        .htaccess Editor
                                    </button>
                                    <button type="button"
                                        onClick={() => { setTechSubTab('custom_pages'); loadCustom404(); }}
                                        className={`px-3 py-1.5 rounded-md font-medium text-xs transition-all ${techSubTab === 'custom_pages' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-800'}`}
                                    >
                                        Custom Error Pages
                                    </button>
                                </div>
                            </div>

                            {/* robots.txt editor */}
                            {techSubTab === 'robots' && (
                                <div className="bg-slate-50 p-6 rounded-lg border border-slate-200">
                                    <h3 className="text-lg font-medium text-slate-800 mb-2">robots.txt Editor</h3>
                                    <p className="text-sm text-slate-500 mb-4">
                                        WordPress and other plugins build robots.txt. Holvek adds its sitemap lines, and AI crawler rules when you turn those on.
                                    </p>

                                    {robotsStatus && (
                                        <Notice status={robotsStatus.type} isDismissible={true} onRemove={() => setRobotsStatus(null)} className="mb-4">
                                            {robotsStatus.message}
                                        </Notice>
                                    )}

                                    {isLoadingRobots ? (
                                        <div className="p-8 text-center"><Spinner /></div>
                                    ) : (
                                        <>
                                            <label htmlFor="holvek-robots-txt" className="block text-sm font-medium text-slate-700 mb-2">
                                                Custom robots.txt
                                            </label>
                                            <textarea
                                                id="holvek-robots-txt"
                                                value={robotsTxt}
                                                onChange={(e) => setRobotsTxt(e.target.value)}
                                                rows={12}
                                                style={{
                                                    width: '100%', fontFamily: 'monospace', fontSize: '13px',
                                                    padding: '12px', border: '1px solid #cbd5e1', borderRadius: '6px',
                                                    background: '#fff', lineHeight: '1.6', resize: 'vertical'
                                                }}
                                            />
                                            <label htmlFor="holvek-robots-replace" className="flex items-start gap-2 text-sm text-slate-700 bg-amber-50 border border-amber-200 rounded p-3 mt-4">
                                                <input
                                                    id="holvek-robots-replace"
                                                    type="checkbox"
                                                    className="mt-1"
                                                    checked={robotsReplace && robotsTxt.trim() !== ''}
                                                    disabled={robotsTxt.trim() === ''}
                                                    onChange={(e) => setRobotsReplace(e.target.checked)}
                                                />
                                                <span>Replace the generated robots.txt with this document. Lines from WordPress and other plugins are removed.</span>
                                            </label>
                                            <div className="mb-4 pt-4 border-t border-slate-200">
                                                <ToggleControl
                                                    label="AI Crawler Manager (Bot Blocker)"
                                                    help="Toggle-based controls to block specific AI crawlers (GPTBot, CCBot, Google-Extended, etc.) via robots.txt and HTTP headers."
                                                    checked={blockAiBots}
                                                    onChange={(checked) => setBlockAiBots(checked)}
                                                />
                                            </div>
                                            <div className="mt-4">
                                                <Button isPrimary isBusy={isSavingRobots} onClick={saveRobotsTxt}>
                                                    {isSavingRobots ? 'Saving...' : 'Save robots.txt'}
                                                </Button>
                                            </div>
                                        </>
                                    )}
                                </div>
                            )}

                            {/* Redirects Manager */}
                            {techSubTab === 'redirects' && (
                                <div className="space-y-6">
                                    <div className="bg-slate-50 p-6 rounded-lg border border-slate-200">
                                        <h3 className="text-lg font-medium text-slate-800 mb-2">Add New 301/302 Redirect</h3>
                                        <p className="text-sm text-slate-500 mb-4">
                                            Create absolute or relative redirects for old or broken URLs to maintain link juice and rank authority.
                                        </p>

                                        {redirectsStatus && (
                                            <Notice status={redirectsStatus.type} isDismissible={true} onRemove={() => setRedirectsStatus(null)} className="mb-4">
                                                {redirectsStatus.message}
                                            </Notice>
                                        )}

                                        <div className="grid grid-cols-1 md:grid-cols-12 gap-4 items-end">
                                            <div className="md:col-span-4">
                                                <TextControl
                                                    label="Source URL path"
                                                    value={newRedirect.source}
                                                    onChange={(v) => setNewRedirect({ ...newRedirect, source: v })}
                                                    placeholder="e.g. /old-product-slug"
                                                />
                                            </div>
                                            <div className="md:col-span-4">
                                                <TextControl
                                                    label="Target URL path"
                                                    value={newRedirect.target}
                                                    onChange={(v) => setNewRedirect({ ...newRedirect, target: v })}
                                                    placeholder="e.g. /new-product-slug"
                                                />
                                            </div>
                                            <div className="md:col-span-2">
                                                <label className="block text-xs font-semibold text-slate-600 mb-2">Redirect Code</label>
                                                <select
                                                    value={newRedirect.code}
                                                    onChange={(e) => setNewRedirect({ ...newRedirect, code: parseInt(e.target.value) })}
                                                    style={{ width: '100%', height: '30px', padding: '0 8px', border: '1px solid #cbd5e1', borderRadius: '4px' }}
                                                >
                                                    <option value={301}>301 Permanent</option>
                                                    <option value={302}>302 Temporary</option>
                                                    <option value={307}>307 Temporary</option>
                                                    <option value={410}>410 Gone</option>
                                                </select>
                                            </div>
                                            <div className="md:col-span-2 flex items-center h-10">
                                                <label className="flex items-center text-xs font-semibold text-slate-600 gap-1.5 cursor-pointer">
                                                    <input
                                                        type="checkbox"
                                                        checked={newRedirect.is_regex}
                                                        onChange={(e) => setNewRedirect({ ...newRedirect, is_regex: e.target.checked })}
                                                    />
                                                    Regex Pattern
                                                </label>
                                            </div>
                                        </div>

                                        <div className="mt-4">
                                            <Button isPrimary onClick={addRedirect}>Add Redirect Rule</Button>
                                        </div>
                                    </div>

                                    {/* Active Redirect Rules */}
                                    <div className="bg-white p-6 rounded-lg border border-slate-200">
                                        <h3 className="text-lg font-medium text-slate-800 mb-4">Active Redirect Rules</h3>
                                        {isLoadingRedirects ? (
                                            <div className="p-8 text-center"><Spinner /></div>
                                        ) : redirects.length === 0 ? (
                                            <p className="text-sm text-slate-500 italic">No active redirect rules found.</p>
                                        ) : (
                                            <div className="overflow-x-auto">
                                                <table className="min-w-full divide-y divide-slate-200">
                                                    <thead>
                                                        <tr>
                                                            <th className="px-4 py-2 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">Source</th>
                                                            <th className="px-4 py-2 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">Target</th>
                                                            <th className="px-4 py-2 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">Type</th>
                                                            <th className="px-4 py-2 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">Regex</th>
                                                            <th className="px-4 py-2 text-right text-xs font-semibold text-slate-600 uppercase tracking-wider">Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody className="divide-y divide-slate-100">
                                                        {redirects.map((rule) => (
                                                            <tr key={rule.id}>
                                                                <td className="px-4 py-3 text-xs font-mono text-slate-700 max-w-xs truncate" title={rule.source}>{rule.source}</td>
                                                                <td className="px-4 py-3 text-xs font-mono text-slate-700 max-w-xs truncate" title={rule.target}>{rule.target}</td>
                                                                <td className="px-4 py-3 text-xs text-slate-600 font-semibold">{rule.code}</td>
                                                                <td className="px-4 py-3 text-xs text-slate-500">{rule.is_regex ? 'Yes' : 'No'}</td>
                                                                <td className="px-4 py-3 text-right">
                                                                    <Button isDestructive isSmall onClick={() => deleteRedirect(rule.id)}>Delete</Button>
                                                                </td>
                                                            </tr>
                                                        ))}
                                                    </tbody>
                                                </table>
                                            </div>
                                        )}
                                        {redirectTotalPages > 1 && (
                                            <div className="mt-4 flex items-center justify-between">
                                                <button type="button" className="px-3 py-1 text-sm border rounded disabled:opacity-40" disabled={redirectPage <= 1} onClick={() => fetchRedirects(redirectPage - 1)}>Previous page</button>
                                                <span className="text-xs text-slate-500">Page {redirectPage} of {redirectTotalPages}</span>
                                                <button type="button" className="px-3 py-1 text-sm border rounded disabled:opacity-40" disabled={redirectPage >= redirectTotalPages} onClick={() => fetchRedirects(redirectPage + 1)}>Next page</button>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            )}

                            {/* 404 Error Log Monitor */}
                            {techSubTab === '404s' && (
                                <div className="bg-white p-6 rounded-lg border border-slate-200 space-y-4">
                                    <div className="flex justify-between items-center">
                                        <div>
                                            <h3 className="text-lg font-medium text-slate-800 m-0">Recent 404 Crawl Errors</h3>
                                            <p className="text-sm text-slate-500 m-0 mt-1">Real-time broken links detected by search engines or users. Cap limit 100.</p>
                                        </div>
                                        {logs404.length > 0 && (
                                            <Button isDestructive isSmall onClick={clearAll404Logs}>Clear All Logs</Button>
                                        )}
                                    </div>

                                    {isLoading404 ? (
                                        <div className="p-8 text-center"><Spinner /></div>
                                    ) : logs404.length === 0 ? (
                                        <div className="p-12 text-center text-slate-400 border border-dashed rounded-lg bg-slate-50">
                                            <span style={{ fontSize: '24px' }}>🛡️</span>
                                            <p className="m-0 mt-2 text-sm">Perfect! No 404 crawl errors currently logged.</p>
                                        </div>
                                    ) : (
                                        <div className="overflow-x-auto">
                                            <table className="min-w-full divide-y divide-slate-200">
                                                <thead>
                                                    <tr>
                                                        <th className="px-4 py-2 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">Broken URL</th>
                                                        <th className="px-4 py-2 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">Hits</th>
                                                        <th className="px-4 py-2 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">Last Hit</th>
                                                        <th className="px-4 py-2 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">Referer</th>
                                                        <th className="px-4 py-2 text-right text-xs font-semibold text-slate-600 uppercase tracking-wider">Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-y divide-slate-100">
                                                    {logs404.map((log) => (
                                                        <tr key={log.id}>
                                                            <td className="px-4 py-3 text-xs font-mono text-slate-800 font-semibold" title={log.uri}>{log.uri}</td>
                                                            <td className="px-4 py-3 text-xs text-slate-600">
                                                                <span className="px-2 py-0.5 rounded-full bg-rose-50 text-rose-700 border border-rose-100 font-semibold">{log.hits}</span>
                                                            </td>
                                                            <td className="px-4 py-3 text-xs text-slate-500">{log.last_hit}</td>
                                                            <td className="px-4 py-3 text-xs text-slate-500 max-w-xs truncate" title={log.referer || 'Direct Hit'}>{log.referer || 'Direct Hit'}</td>
                                                            <td className="px-4 py-3 text-right flex justify-end gap-2">
                                                                <Button isPrimary isSmall onClick={() => convert404ToRedirect(log.uri)}>Redirect</Button>
                                                                <Button isDestructive isSmall onClick={() => delete404Log(log.id)}>Dismiss</Button>
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    )}
                                    {logs404TotalPages > 1 && (
                                        <div className="flex items-center justify-between">
                                            <button type="button" className="px-3 py-1 text-sm border rounded disabled:opacity-40" disabled={logs404Page <= 1} onClick={() => fetch404Logs(logs404Page - 1)}>Previous page</button>
                                            <span className="text-xs text-slate-500">Page {logs404Page} of {logs404TotalPages}</span>
                                            <button type="button" className="px-3 py-1 text-sm border rounded disabled:opacity-40" disabled={logs404Page >= logs404TotalPages} onClick={() => fetch404Logs(logs404Page + 1)}>Next page</button>
                                        </div>
                                    )}
                                </div>
                            )}

                            {/* .htaccess Editor */}
                            {techSubTab === 'htaccess' && (
                                <div className="space-y-4">
                                    <h3 className="text-lg font-medium text-slate-800 m-0">Apache .htaccess</h3>
                                    {htaccessLoading && <div className="flex items-center gap-3 p-6 bg-slate-50 rounded-lg"><Spinner /><span className="text-slate-500">Checking the .htaccess editor…</span></div>}
                                    {htaccess && (
                                        <>
                                            <div className="p-4 bg-amber-50 border border-amber-200 rounded-lg text-amber-900 text-sm" role="status">
                                                {htaccess.message}
                                            </div>
                                            {htaccessMsg && (
                                                <div role="status" className={`text-sm px-3 py-2 rounded ${htaccessMsg.type === 'success' ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800'}`}>
                                                    {htaccessMsg.text}
                                                </div>
                                            )}
                                            {!htaccess.forbidden && !htaccess.locked && !htaccess.enabled && (
                                                <button type="button" onClick={() => setHtaccessEnabled(true)} disabled={htaccessSaving} className="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded hover:bg-indigo-700 disabled:opacity-50">
                                                    {htaccessSaving ? 'Turning on…' : 'Turn on .htaccess editor'}
                                                </button>
                                            )}
                                            {htaccess.enabled && (
                                                <>
                                                    <div className="bg-white border border-slate-200 rounded-lg overflow-hidden">
                                                        <div className="flex items-center justify-between px-4 py-2 bg-slate-50 border-b border-slate-200">
                                                            <label htmlFor="holvek-htaccess-editor" className="text-xs font-mono font-semibold text-slate-600">.htaccess</label>
                                                            <span className="text-xs text-slate-400">{htaccessContent.split('\n').length} lines{htaccess.writable ? '' : ' · read only'}</span>
                                                        </div>
                                                        <textarea id="holvek-htaccess-editor" value={htaccessContent} onChange={e => setHtaccessContent(e.target.value)} disabled={!htaccess.writable || htaccessSaving} rows={20} spellCheck={false}
                                                            className="w-full font-mono text-xs p-4 border-0 outline-none resize-y bg-slate-900 text-green-300 leading-relaxed"
                                                            style={{ minHeight: '320px' }} />
                                                    </div>
                                                    {Array.isArray(htaccess.warnings) && htaccess.warnings.length > 0 && (
                                                        <ul className="m-0 p-4 bg-amber-50 border border-amber-200 rounded-lg text-amber-900 text-sm list-disc pl-8">
                                                            {htaccess.warnings.map((warning) => <li key={warning}>{warning}</li>)}
                                                        </ul>
                                                    )}
                                                    <div className="flex flex-wrap items-center gap-3">
                                                        <button type="button" onClick={saveHtaccess} disabled={htaccessSaving || !htaccess.writable} className="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded hover:bg-indigo-700 disabled:opacity-50">
                                                            {htaccessSaving ? 'Saving…' : 'Save .htaccess'}
                                                        </button>
                                                        <button type="button" onClick={() => setHtaccessEnabled(false)} disabled={htaccessSaving} className="px-4 py-2 bg-white text-slate-700 text-sm font-medium rounded border border-slate-300 hover:bg-slate-50 disabled:opacity-50">
                                                            Turn off editor
                                                        </button>
                                                        {!htaccess.writable && <span className="text-xs text-slate-500">The file is not writable, so saving is unavailable.</span>}
                                                    </div>
                                                    {Array.isArray(htaccess.backups) && htaccess.backups.length > 0 && (
                                                        <div className="bg-white border border-slate-200 rounded-lg p-4">
                                                            <h4 className="text-sm font-semibold text-slate-700 m-0 mb-3">Private backups</h4>
                                                            <ul className="m-0 p-0 list-none space-y-2">
                                                                {htaccess.backups.map((backup) => (
                                                                    <li key={backup.filename} className="flex items-center justify-between gap-3 text-sm">
                                                                        <span className="text-slate-600">{backup.created_at} · {backup.size} bytes</span>
                                                                        <button type="button" onClick={() => restoreHtaccessBackup(backup.filename)} disabled={htaccessSaving} className="px-3 py-1.5 bg-slate-100 text-slate-800 text-xs font-medium rounded hover:bg-slate-200 disabled:opacity-50">
                                                                            Restore
                                                                        </button>
                                                                    </li>
                                                                ))}
                                                            </ul>
                                                        </div>
                                                    )}
                                                </>
                                            )}
                                        </>
                                    )}
                                </div>
                            )}

                            {/* Custom Error Pages */}
                            {techSubTab === 'custom_pages' && (
                                <div className="space-y-6">
                                    <div>
                                        <h3 className="text-base font-semibold text-slate-800 mb-1">Custom 404 Page</h3>
                                        <p className="text-sm text-slate-500 mb-4">Select a published page to display when visitors hit a missing URL. HTTP 404 status is preserved for correct SEO signalling.</p>
                                        {isLoading404Page ? (
                                            <div className="flex items-center gap-3 p-4 bg-slate-50 rounded-lg"><Spinner /><span className="text-slate-500">Loading pages…</span></div>
                                        ) : (
                                            <div className="flex gap-3 items-end">
                                                <div className="flex-1">
                                                    <label className="block text-xs font-semibold text-slate-700 mb-1">404 Template Page</label>
                                                    <select
                                                        value={custom404PageId}
                                                        onChange={e => setCustom404PageId(Number(e.target.value))}
                                                        className="w-full p-2 border border-slate-300 rounded text-sm bg-white"
                                                    >
                                                        <option value={0}>— Use theme default 404.php —</option>
                                                        {available404Pages.map(p => (
                                                            <option key={p.id} value={p.id}>{p.title}</option>
                                                        ))}
                                                    </select>
                                                </div>
                                                <button type="button" onClick={saveCustom404Page} disabled={isSaving404Page} className="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded hover:bg-indigo-700 disabled:opacity-50">
                                                    {isSaving404Page ? 'Saving…' : 'Save'}
                                                </button>
                                            </div>
                                        )}
                                        {custom404Msg && <p className={`mt-2 text-sm ${custom404Msg.type === 'success' ? 'text-green-700' : 'text-red-600'}`}>{custom404Msg.text}</p>}
                                    </div>
                                    <div className="pt-5 border-t border-slate-200">
                                        <h3 className="text-base font-semibold text-slate-800 mb-1">Custom 403 Page</h3>
                                        <p className="text-sm text-slate-500">A branded HTML 403 response is served automatically when AI bot blocking is enabled. No further configuration required — enable AI blocking in the robots.txt tab above.</p>
                                    </div>
                                </div>
                            )}
                        </div>
                    )}

                    {/* ══════════════ HTML SITEMAP TAB ══════════════ */}
                    {activeTab === 'html_sitemap' && (
                        <div className="space-y-6 max-w-3xl">
                            <div>
                                <h2 className="text-xl font-semibold m-0 text-slate-800">HTML Sitemap Builder</h2>
                                <p className="text-slate-500 text-sm mt-1">Configure and preview your visual HTML sitemap. Use the shortcode <code className="bg-slate-100 px-1.5 py-0.5 rounded text-indigo-700 font-mono text-xs">[holvek_sitemap]</code> to embed it on any page.</p>
                            </div>

                            {sitemapLoading && <div className="flex items-center gap-3 p-6 bg-slate-50 rounded-lg"><Spinner /><span className="text-slate-500">Loading sitemap settings…</span></div>}

                            {sitemapCfg && (
                                <div className="bg-white border rounded-lg divide-y">
                                    {/* Settings form */}
                                    <div className="p-6 space-y-4">
                                        <h3 className="text-sm font-semibold text-slate-700 m-0">Configuration</h3>
                                        <div className="grid grid-cols-2 gap-4">
                                            <div>
                                                <label className="block text-xs font-semibold text-slate-600 mb-1">Post Types <span className="font-normal text-slate-400">(comma-separated slugs)</span></label>
                                                <input type="text" value={sitemapCfg.post_types} onChange={e => setSitemapCfg(c => ({ ...c, post_types: e.target.value }))} className="w-full border border-slate-200 rounded px-3 py-2 text-sm" placeholder="post,page" />
                                            </div>
                                            <div>
                                                <label className="block text-xs font-semibold text-slate-600 mb-1">Exclude Post IDs <span className="font-normal text-slate-400">(comma-separated)</span></label>
                                                <input type="text" value={sitemapCfg.exclude_ids} onChange={e => setSitemapCfg(c => ({ ...c, exclude_ids: e.target.value }))} className="w-full border border-slate-200 rounded px-3 py-2 text-sm" placeholder="12,34,56" />
                                            </div>
                                            <div>
                                                <label className="block text-xs font-semibold text-slate-600 mb-1">Sort Order</label>
                                                <select value={sitemapCfg.order} onChange={e => setSitemapCfg(c => ({ ...c, order: e.target.value }))} className="w-full border border-slate-200 rounded px-3 py-2 text-sm bg-white">
                                                    <option value="menu_order">Menu Order</option>
                                                    <option value="title">Title (A–Z)</option>
                                                    <option value="date">Date Published</option>
                                                    <option value="modified">Last Modified</option>
                                                </select>
                                            </div>
                                            <div className="flex items-center gap-3 pt-5">
                                                <input type="checkbox" id="show_count" checked={!!sitemapCfg.show_count} onChange={e => setSitemapCfg(c => ({ ...c, show_count: e.target.checked }))} className="w-4 h-4 rounded border-slate-300 text-indigo-600" />
                                                <label htmlFor="show_count" className="text-sm text-slate-700">Show post count next to section headings</label>
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-3 pt-2">
                                            {sitemapSaveMsg && <span className={`text-sm ${sitemapSaveMsg.type === 'success' ? 'text-green-700' : 'text-red-600'}`}>{sitemapSaveMsg.text}</span>}
                                            <button type="button" onClick={saveSitemapCfg} disabled={sitemapSaving} className="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded hover:bg-indigo-700 disabled:opacity-50">{sitemapSaving ? 'Saving…' : 'Save Settings'}</button>
                                            <button type="button" onClick={loadSitemapPreview} className="px-4 py-2 bg-slate-100 text-slate-700 text-sm font-medium rounded hover:bg-slate-200">👁 Preview</button>
                                        </div>
                                    </div>

                                    {/* Shortcode helper */}
                                    <div className="p-4 bg-slate-50 flex items-center gap-3">
                                        <span className="text-xs text-slate-500">Shortcode:</span>
                                        <code className="bg-white border border-slate-200 text-indigo-700 font-mono text-xs px-3 py-1.5 rounded select-all">[holvek_sitemap]</code>
                                        <span className="text-xs text-slate-400">or override per-instance: <code className="font-mono">[holvek_sitemap post_types="post" order="title"]</code></span>
                                    </div>

                                    {/* Preview */}
                                    {sitemapPreview && (
                                        <div className="p-6 space-y-4">
                                            <div className="flex items-center justify-between">
                                                <h3 className="text-sm font-semibold text-slate-700 m-0">Live Preview — {sitemapPreview.total} pages</h3>
                                                <button type="button" onClick={() => setSitemapPreview(null)} className="text-xs text-slate-400 hover:text-slate-600 bg-transparent border-none cursor-pointer">✕ Close</button>
                                            </div>
                                            {sitemapPreview.tree.map((section, i) => (
                                                <div key={i}>
                                                    <h4 className="text-sm font-semibold text-slate-700 border-b border-slate-200 pb-1 mb-2">
                                                        {section.label} {sitemapPreview.show_count && <span className="font-normal text-slate-400 text-xs">({section.count})</span>}
                                                    </h4>
                                                    <div className="flex flex-wrap gap-1.5">
                                                        {section.items.map((item, j) => (
                                                            <a key={j} href={item.url} target="_blank" rel="noreferrer" className="text-xs text-indigo-600 hover:underline bg-indigo-50 px-2 py-0.5 rounded border border-indigo-100">
                                                                {item.title || '(no title)'}
                                                            </a>
                                                        ))}
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </div>
                            )}
                        </div>
                    )}

                    {activeTab === 'sitemaps' && (
                        <div className="space-y-6 max-w-3xl">
                                    <h2 className="text-xl font-semibold m-0 text-slate-800">XML Sitemaps</h2>
                            <p className="text-slate-600">Use this only when you intend Holvek to provide your sitemap. It is disabled by default so WordPress Core and existing SEO plugins keep control.</p>

                            {isLoadingSitemaps ? (
                                <div className="p-8 text-center"><Spinner /></div>
                            ) : (
                                <div className="bg-slate-50 p-6 rounded-lg border border-slate-200 space-y-6">
                                    {sitemapsStatus && (
                                        <Notice status={sitemapsStatus.type} isDismissible={true} onRemove={() => setSitemapsStatus(null)}>
                                            {sitemapsStatus.message}
                                        </Notice>
                                    )}

                                    <div className="space-y-4">
                                        <ToggleControl
                                            label="Use Holvek as the sitemap provider"
                                            help="Disables WordPress Core sitemaps while active. Do not enable when another SEO plugin owns your sitemap."
                                            checked={sitemapsData.enable_index_sitemap}
                                            onChange={(val) => setSitemapsData({ ...sitemapsData, enable_index_sitemap: val })}
                                        />

                                        <ToggleControl
                                            label="Enable Google News XML Sitemap"
                                            help="Generates a news sitemap containing only posts published within the last 48 hours for immediate crawl indexing."
                                            checked={sitemapsData.enable_news_sitemap}
                                            onChange={(val) => setSitemapsData({ ...sitemapsData, enable_news_sitemap: val })}
                                        />

                                        <ToggleControl
                                            label="Enable Video XML Sitemap"
                                            help="Generates an XML sitemap of all video-enriched posts, automatically parsing embeds like YouTube and Vimeo."
                                            checked={sitemapsData.enable_video_sitemap}
                                            onChange={(val) => setSitemapsData({ ...sitemapsData, enable_video_sitemap: val })}
                                        />
                                    </div>

                                    {/* Exclusions */}
                                    <div className="pt-6 border-t border-slate-200">
                                        <h3 className="text-lg font-medium text-slate-800 mb-2">Exclude Content Types</h3>
                                        <p className="text-sm text-slate-500 mb-4">Uncheck post types you want to completely hide from crawlers inside the sitemaps.</p>
                                        <div className="grid grid-cols-2 md:grid-cols-3 gap-3">
                                            {sitemapsData.available_types && sitemapsData.available_types.map((type) => (
                                                <label key={type.name} className="flex items-center text-sm text-slate-700 gap-2 cursor-pointer">
                                                    <input
                                                        type="checkbox"
                                                        checked={!sitemapsData.exclude_types.includes(type.name)}
                                                        onChange={(e) => {
                                                            const newExclude = e.target.checked
                                                                ? sitemapsData.exclude_types.filter(t => t !== type.name)
                                                                : [...sitemapsData.exclude_types, type.name];
                                                            setSitemapsData({ ...sitemapsData, exclude_types: newExclude });
                                                        }}
                                                    />
                                                    {type.label}
                                                </label>
                                            ))}
                                        </div>
                                    </div>

                                    {/* Manual Exclusions */}
                                    <div className="pt-6 border-t border-slate-200">
                                        <h3 className="text-lg font-medium text-slate-800 mb-2">Exclude Specific Posts or Pages</h3>
                                        <p className="text-sm text-slate-500 mb-3">Provide a comma-separated list of Post IDs (e.g. 12, 104, 301) to manually exclude from all sitemaps.</p>
                                        <TextControl
                                            value={sitemapsData.exclude_posts}
                                            onChange={(val) => setSitemapsData({ ...sitemapsData, exclude_posts: val })}
                                            placeholder="e.g. 15, 340, 219"
                                        />
                                    </div>

                                    {/* Tuning Ranges */}
                                    <div className="pt-6 border-t border-slate-200 space-y-4">
                                        <h3 className="text-lg font-medium text-slate-800 mb-2">Sitemap Signal Tuning</h3>
                                        <p className="text-sm text-slate-500 mb-4">Tune Priority (0.0 to 1.0) and Change Frequency hints to guide search engine crawls.</p>
                                        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                                            <div className="space-y-4 bg-white p-4 rounded-lg border border-slate-200 shadow-sm">
                                                <h4 className="font-semibold text-xs text-slate-400 uppercase tracking-wider">Posts Settings</h4>
                                                <div>
                                                    <label className="block text-xs font-semibold text-slate-600 mb-1">Post Priority: {sitemapsData.sitemap_priority_post}</label>
                                                    <input 
                                                        type="range" 
                                                        min="0.0" 
                                                        max="1.0" 
                                                        step="0.1" 
                                                        value={sitemapsData.sitemap_priority_post} 
                                                        onChange={(e) => setSitemapsData({ ...sitemapsData, sitemap_priority_post: e.target.value })} 
                                                        className="w-full accent-amber-500 cursor-pointer"
                                                    />
                                                </div>
                                                <div>
                                                    <label className="block text-xs font-semibold text-slate-600 mb-1">Post Change Frequency</label>
                                                    <select 
                                                        value={sitemapsData.sitemap_changefreq_post}
                                                        onChange={(e) => setSitemapsData({ ...sitemapsData, sitemap_changefreq_post: e.target.value })}
                                                        className="w-full p-2 border border-slate-300 rounded text-sm bg-white"
                                                    >
                                                        <option value="always">Always</option>
                                                        <option value="hourly">Hourly</option>
                                                        <option value="daily">Daily</option>
                                                        <option value="weekly">Weekly</option>
                                                        <option value="monthly">Monthly</option>
                                                        <option value="yearly">Yearly</option>
                                                        <option value="never">Never</option>
                                                    </select>
                                                </div>
                                            </div>
                                            
                                            <div className="space-y-4 bg-white p-4 rounded-lg border border-slate-200 shadow-sm">
                                                <h4 className="font-semibold text-xs text-slate-400 uppercase tracking-wider">Pages Settings</h4>
                                                <div>
                                                    <label className="block text-xs font-semibold text-slate-600 mb-1">Page Priority: {sitemapsData.sitemap_priority_page}</label>
                                                    <input 
                                                        type="range" 
                                                        min="0.0" 
                                                        max="1.0" 
                                                        step="0.1" 
                                                        value={sitemapsData.sitemap_priority_page} 
                                                        onChange={(e) => setSitemapsData({ ...sitemapsData, sitemap_priority_page: e.target.value })} 
                                                        className="w-full accent-amber-500 cursor-pointer"
                                                    />
                                                </div>
                                                <div>
                                                    <label className="block text-xs font-semibold text-slate-600 mb-1">Page Change Frequency</label>
                                                    <select 
                                                        value={sitemapsData.sitemap_changefreq_page}
                                                        onChange={(e) => setSitemapsData({ ...sitemapsData, sitemap_changefreq_page: e.target.value })}
                                                        className="w-full p-2 border border-slate-300 rounded text-sm bg-white"
                                                    >
                                                        <option value="always">Always</option>
                                                        <option value="hourly">Hourly</option>
                                                        <option value="daily">Daily</option>
                                                        <option value="weekly">Weekly</option>
                                                        <option value="monthly">Monthly</option>
                                                        <option value="yearly">Yearly</option>
                                                        <option value="never">Never</option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    {/* Info Cards containing live sitemap links */}
                                    <div className="pt-6 border-t border-slate-200">
                                        <h3 className="text-lg font-medium text-slate-800 mb-3">Live XML Sitemaps</h3>
                                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                            {sitemapsData.enable_index_sitemap && (
                                                <div className="bg-white p-4 rounded-lg border border-slate-200 shadow-sm flex items-center justify-between">
                                                    <div>
                                                        <div className="font-semibold text-xs text-slate-400 uppercase">Main Sitemap</div>
                                                        <div className="text-slate-800 font-medium text-sm mt-1 truncate">sitemap.xml</div>
                                                    </div>
                                                    <a href={`${window.location.origin}/sitemap.xml`} target="_blank" rel="noopener noreferrer" className="text-amber-500 hover:text-amber-700 text-xs font-semibold flex items-center">
                                                        Open sitemap ↗
                                                     </a>
                                                </div>
                                            )}
                                            {sitemapsData.enable_news_sitemap && (
                                                <div className="bg-white p-4 rounded-lg border border-slate-200 shadow-sm flex items-center justify-between">
                                                    <div>
                                                        <div className="font-semibold text-xs text-slate-400 uppercase">Google News Sitemap</div>
                                                        <div className="text-slate-800 font-medium text-sm mt-1 truncate">news-sitemap.xml</div>
                                                    </div>
                                                    <a href={`${window.location.origin}/news-sitemap.xml`} target="_blank" rel="noopener noreferrer" className="text-amber-500 hover:text-amber-700 text-xs font-semibold flex items-center">
                                                        Open sitemap ↗
                                                    </a>
                                                </div>
                                            )}
                                            {sitemapsData.enable_video_sitemap && (
                                                <div className="bg-white p-4 rounded-lg border border-slate-200 shadow-sm flex items-center justify-between">
                                                    <div>
                                                        <div className="font-semibold text-xs text-slate-400 uppercase">Video Sitemap</div>
                                                        <div className="text-slate-800 font-medium text-sm mt-1 truncate">video-sitemap.xml</div>
                                                    </div>
                                                    <a href={`${window.location.origin}/video-sitemap.xml`} target="_blank" rel="noopener noreferrer" className="text-amber-500 hover:text-amber-700 text-xs font-semibold flex items-center">
                                                        Open sitemap ↗
                                                    </a>
                                                </div>
                                            )}
                                        </div>
                                    </div>

                                    <div className="pt-4">
                                        <Button isPrimary isBusy={isSavingSitemaps} onClick={saveSitemapsSettings}>
                                            {isSavingSitemaps ? 'Saving...' : 'Save Sitemap Settings'}
                                        </Button>
                                    </div>
                                </div>
                            )}
                        </div>
                    )}

                    {activeTab === 'schema' && (
                        <div className="space-y-6 max-w-3xl">
                            <h2 className="text-xl font-semibold m-0 text-slate-800">Schema Markup Templates</h2>
                            <p className="text-slate-600">
                                Build conditional rules to dynamically inject schemas like Article, Recipe, Event, and FAQPage across your site based on post types or categories.
                            </p>

                            {isLoadingSchemaRules ? (
                                <div className="p-8 text-center"><Spinner /></div>
                            ) : (
                                <div className="space-y-6">
                                    {schemaRulesStatus && (
                                        <Notice status={schemaRulesStatus.type} isDismissible={true} onRemove={() => setSchemaRulesStatus(null)}>
                                            {schemaRulesStatus.message}
                                        </Notice>
                                    )}

                                    {/* Active Display Rules */}
                                    <div className="bg-slate-50 p-6 rounded-lg border border-slate-200">
                                        <h3 className="text-lg font-medium text-slate-800 mb-2">Active Display Rules</h3>
                                        {schemaRules.length === 0 ? (
                                            <p className="text-sm text-slate-500 italic">No global schema display rules configured yet. Standard schemas will output based on page types.</p>
                                        ) : (
                                            <div className="space-y-2 mt-4">
                                                {schemaRules.map((rule, idx) => (
                                                    <div key={idx} className="bg-white p-3 rounded border border-slate-200 shadow-sm flex items-center justify-between">
                                                        <div className="flex items-center gap-2 text-sm text-slate-700">
                                                            <span>On post type</span>
                                                            <strong className="text-amber-600 font-semibold bg-amber-50 px-2 py-0.5 rounded">{rule.post_type}</strong>
                                                            {rule.category && rule.category !== 'all' && (
                                                                <>
                                                                    <span>in category</span>
                                                                    <strong className="text-amber-600 font-semibold bg-amber-50 px-2 py-0.5 rounded">
                                                                        {availableCategories.find(c => String(c.id) === String(rule.category))?.name || rule.category}
                                                                    </strong>
                                                                </>
                                                            )}
                                                            <span>output</span>
                                                            <strong className="text-slate-800 font-semibold uppercase">{rule.schema_type}</strong>
                                                        </div>
                                                        <button type="button" 
                                                            onClick={() => {
                                                                const updated = schemaRules.filter((_, i) => i !== idx);
                                                                setSchemaRules(updated);
                                                            }}
                                                            className="text-xs font-semibold text-red-500 hover:text-red-700 bg-red-50 hover:bg-red-100 px-2.5 py-1 rounded transition-colors border border-transparent cursor-pointer"
                                                        >
                                                            Remove
                                                        </button>
                                                    </div>
                                                ))}
                                            </div>
                                        )}
                                    </div>

                                    {/* Add New Display Rule */}
                                    <div className="bg-slate-50 p-6 rounded-lg border border-slate-200">
                                        <h3 className="text-lg font-medium text-slate-800 mb-4">Create Global Display Rule</h3>
                                        <SchemaRuleForm 
                                            postTypes={availablePostTypes}
                                            categories={availableCategories}
                                            onAddRule={(newRule) => {
                                                setSchemaRules([...schemaRules, newRule]);
                                            }}
                                        />
                                    </div>

                                    <div className="pt-4 border-t border-slate-200">
                                        <Button isPrimary isBusy={isSavingSchemaRules} onClick={() => saveSchemaRules(schemaRules)}>
                                            {isSavingSchemaRules ? 'Saving...' : 'Save Schema Rules'}
                                        </Button>
                                    </div>
                                </div>
                            )}
                        </div>
                    )}
                    {activeTab === 'social' && (
                        <div className="space-y-6">
                            <div className="flex justify-between items-center mb-6">
                                <h2 className="text-xl font-semibold m-0 text-slate-800">Social Accounts & Auto-Sharing</h2>
                                <div className="flex items-center gap-2">
                                    <span className="text-sm font-medium text-slate-600">Global Auto-Share</span>
                                    <label className="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" className="sr-only peer" checked={globalAutoShare} onChange={(e) => toggleGlobalAutoShare(e.target.checked)} />
                                        <div className="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-amber-500"></div>
                                    </label>
                                </div>
                            </div>
                            
                            <div className="bg-white border border-slate-200 rounded-lg shadow-sm">
                                <div className="p-4 border-b border-slate-200 bg-slate-50 flex justify-between items-center">
                                    <h3 className="font-semibold text-slate-800 m-0 text-sm">Connected Profiles</h3>
                                </div>
                                <div className="p-4">
                                    {isLoadingSocial ? (
                                        <div className="text-sm text-slate-500">Loading accounts...</div>
                                    ) : socialAccounts.length > 0 ? (
                                        <div className="space-y-3">
                                            {socialAccounts.map((acc) => (
                                                <div key={acc.id} className="border border-slate-200 rounded bg-white overflow-hidden">
                                                    <div className="flex justify-between items-center p-3">
                                                        <div className="flex items-center gap-3">
                                                            <div className="w-8 h-8 flex items-center justify-center rounded bg-slate-100 text-slate-600 capitalize font-bold text-xs">
                                                                {acc.network.charAt(0)}
                                                            </div>
                                                            <div>
                                                                <div className="font-semibold text-sm text-slate-800">{acc.profile_name}</div>
                                                                <div className="text-xs text-slate-500 capitalize">{acc.network}</div>
                                                            </div>
                                                        </div>
                                                        <div className="flex gap-2">
                                                            <button type="button" 
                                                                onClick={() => setEditingAccount(editingAccount?.id === acc.id ? null : acc)} 
                                                                className="text-xs text-blue-600 hover:text-blue-800 font-medium bg-blue-50 hover:bg-blue-100 px-3 py-1 rounded transition-colors cursor-pointer border-none"
                                                            >
                                                                {editingAccount?.id === acc.id ? 'Cancel' : 'Edit Routing'}
                                                            </button>
                                                            <button type="button" onClick={() => deleteSocialAccount(acc.id)} className="text-xs text-red-600 hover:text-red-800 font-medium bg-red-50 hover:bg-red-100 px-3 py-1 rounded transition-colors cursor-pointer border-none">
                                                                Disconnect
                                                            </button>
                                                        </div>
                                                    </div>
                                                    
                                                    {editingAccount?.id === acc.id && (
                                                        <div className="p-4 bg-slate-50 border-t border-slate-200 space-y-4">
                                                            <div>
                                                                <label className="block text-xs font-semibold text-slate-700 mb-1">Account / Page Name</label>
                                                                <input 
                                                                    type="text" 
                                                                    className="w-full p-2 border rounded text-sm"
                                                                    value={editingAccount.profile_name}
                                                                    onChange={(e) => setEditingAccount({...editingAccount, profile_name: e.target.value})}
                                                                />
                                                                <p className="text-xs text-slate-500 mt-1 mb-0">Helpful if you have multiple pages connected to the same platform.</p>
                                                            </div>
                                                            
                                                            <div>
                                                                <label className="block text-xs font-semibold text-slate-700 mb-1">Category Routing (Optional)</label>
                                                                <select 
                                                                    multiple 
                                                                    className="w-full p-2 border rounded text-sm"
                                                                    value={editingAccount.bound_categories || []}
                                                                    onChange={(e) => {
                                                                        const options = Array.from(e.target.selectedOptions, option => parseInt(option.value));
                                                                        setEditingAccount({...editingAccount, bound_categories: options});
                                                                    }}
                                                                    style={{ height: '100px' }}
                                                                >
                                                                    {socialCategories.map(cat => (
                                                                        <option key={cat.id} value={cat.id}>{cat.name}</option>
                                                                    ))}
                                                                </select>
                                                                <p className="text-xs text-slate-500 mt-1 mb-0">Select categories to bind. If selected, ONLY posts in these categories will be shared to this account. Leave empty to act as a catch-all.</p>
                                                            </div>

                                                            <div className="pt-2">
                                                                <Button 
                                                                    isPrimary 
                                                                    isBusy={isSavingAccount} 
                                                                    onClick={() => updateSocialAccount(acc.id, editingAccount)}
                                                                >
                                                                    Save Account Settings
                                                                </Button>
                                                            </div>
                                                        </div>
                                                    )}
                                                </div>
                                            ))}
                                        </div>
                                    ) : (
                                        <div className="text-sm text-slate-500 text-center py-6">No social accounts connected yet.</div>
                                    )}
                                </div>
                            </div>

                            <div className="bg-white border border-slate-200 rounded-lg shadow-sm">
                                <div className="p-4 border-b border-slate-200 bg-slate-50">
                                    <h3 className="font-semibold text-slate-800 m-0 text-sm">Platform API Settings</h3>
                                    <p className="text-xs text-slate-500 mt-1 mb-0">Configure your Developer App ID and Secret for each platform to enable native OAuth connections.</p>
                                </div>
                                <div className="p-4 space-y-4">
                                    {['facebook', 'twitter', 'linkedin', 'pinterest'].map(net => (
                                        <div key={net} className="flex gap-4 items-end border-b border-slate-100 pb-4 last:border-0 last:pb-0">
                                            <div className="w-32 font-semibold capitalize text-sm text-slate-700">{net}</div>
                                            <div className="flex-1">
                                                <label className="block text-xs font-semibold text-slate-600 mb-1">App ID / Client ID</label>
                                                <input 
                                                    type="text" 
                                                    className="w-full p-2 border rounded text-sm"
                                                    value={socialApps[net]?.app_id || ''}
                                                    onChange={(e) => setSocialApps({...socialApps, [net]: {...socialApps[net], app_id: e.target.value}})}
                                                />
                                            </div>
                                            <div className="flex-1">
                                                <label className="block text-xs font-semibold text-slate-600 mb-1">App Secret</label>
                                                <input 
                                                    type="password" 
                                                    className="w-full p-2 border rounded text-sm"
                                                    placeholder={socialApps[net]?.app_secret ? '********' : ''}
                                                    onChange={(e) => setSocialApps({...socialApps, [net]: {...socialApps[net], app_secret: e.target.value}})}
                                                />
                                            </div>
                                        </div>
                                    ))}
                                    <div className="pt-2">
                                        <Button isPrimary isBusy={isSavingSocialApps} onClick={saveSocialSettings}>
                                            Save API Credentials
                                        </Button>
                                    </div>
                                </div>
                            </div>

                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div className="p-4 border border-slate-200 rounded-lg flex flex-col items-center text-center justify-center gap-3 bg-slate-50">
                                    <div className="font-semibold text-slate-800">Facebook</div>
                                    <p className="text-xs text-slate-500 m-0">Connect a Facebook Page to share updates automatically.</p>
                                    <button type="button" onClick={() => handleOAuthConnect('facebook')} className="bg-blue-600 text-white border-none rounded px-4 py-2 text-sm font-medium hover:bg-blue-700 cursor-pointer">Connect Facebook</button>
                                </div>
                                <div className="p-4 border border-slate-200 rounded-lg flex flex-col items-center text-center justify-center gap-3 bg-slate-50">
                                    <div className="font-semibold text-slate-800">X (Twitter)</div>
                                    <p className="text-xs text-slate-500 m-0">Connect an X account to auto-tweet published content.</p>
                                    <button type="button" onClick={() => handleOAuthConnect('twitter')} className="bg-slate-900 text-white border-none rounded px-4 py-2 text-sm font-medium hover:bg-black cursor-pointer">Connect X (Twitter)</button>
                                </div>
                                <div className="p-4 border border-slate-200 rounded-lg flex flex-col items-center text-center justify-center gap-3 bg-slate-50">
                                    <div className="font-semibold text-slate-800">LinkedIn</div>
                                    <p className="text-xs text-slate-500 m-0">Connect a LinkedIn Profile or Company Page.</p>
                                    <button type="button" onClick={() => handleOAuthConnect('linkedin')} className="bg-blue-700 text-white border-none rounded px-4 py-2 text-sm font-medium hover:bg-blue-800 cursor-pointer">Connect LinkedIn</button>
                                </div>
                                <div className="p-4 border border-slate-200 rounded-lg flex flex-col items-center text-center justify-center gap-3 bg-slate-50">
                                    <div className="font-semibold text-slate-800">Pinterest</div>
                                    <p className="text-xs text-slate-500 m-0">Connect to pin your latest content automatically.</p>
                                    <button type="button" onClick={() => handleOAuthConnect('pinterest')} className="bg-red-600 text-white border-none rounded px-4 py-2 text-sm font-medium hover:bg-red-700 cursor-pointer">Connect Pinterest</button>
                                </div>
                            </div>

                        </div>
                    )}
                    {activeTab === 'llms' && (
                        <div className="space-y-6">
                            <h2 className="text-xl font-semibold m-0 text-slate-800">LLM Agent Config (llms.txt)</h2>
                            <p className="text-slate-600 mb-6">{__('Holvek publishes an llms.txt file on this site. It does not submit the site to ChatGPT, Perplexity, or Claude, and it does not check whether they mention it.', 'holvek')}</p>
                            
                            {llmsStatus && <Notice status={llmsStatus.type} onRemove={() => setLlmsStatus(null)}>{llmsStatus.message}</Notice>}

                            <div className="bg-white p-6 border rounded shadow-sm space-y-4">
                                <div>
                                    <label className="block text-sm font-semibold text-slate-700 mb-1">Site Description</label>
                                    <TextareaControl
                                        value={llmsData.description}
                                        onChange={(val) => setLlmsData({ ...llmsData, description: val })}
                                        help="A short summary of what your site is about."
                                        rows={2}
                                    />
                                </div>
                                
                                <div className="flex gap-4">
                                    <div className="flex-1">
                                        <ToggleControl
                                            label="Allow Search Indexing"
                                            help="Permit AI search bots to crawl and index your content for answers."
                                            checked={llmsData.allow_index}
                                            onChange={(val) => setLlmsData({ ...llmsData, allow_index: val })}
                                        />
                                    </div>
                                    <div className="flex-1">
                                        <ToggleControl
                                            label="Allow Model Training"
                                            help="Permit AI companies to use your content to train foundational models."
                                            checked={llmsData.allow_training}
                                            onChange={(val) => setLlmsData({ ...llmsData, allow_training: val })}
                                        />
                                    </div>
                                </div>

                                <div>
                                    <label className="block text-sm font-semibold text-slate-700 mb-1">Contact URL</label>
                                    <TextControl
                                        value={llmsData.contact_url}
                                        onChange={(val) => setLlmsData({ ...llmsData, contact_url: val })}
                                        placeholder="e.g. https://yoursite.com/contact or mailto:you@yoursite.com"
                                    />
                                </div>

                                <div>
                                    <label className="block text-sm font-semibold text-slate-700 mb-1">Additional Notes</label>
                                    <TextareaControl
                                        value={llmsData.extra_notes}
                                        onChange={(val) => setLlmsData({ ...llmsData, extra_notes: val })}
                                        help="Any extra rules, terms of service, or instructions for AI agents."
                                        rows={3}
                                    />
                                </div>

                                <Button isPrimary isBusy={isSavingLlms} disabled={isSavingLlms} onClick={saveLlmsSettings}>
                                    Save llms.txt Config
                                </Button>
                            </div>

                            {llmsData.preview && (
                                <div className="mt-8">
                                    <h3 className="text-lg font-semibold text-slate-800">File Preview</h3>
                                    <div className="bg-slate-900 text-slate-50 p-4 rounded text-sm overflow-auto max-h-96 whitespace-pre">
                                        {llmsData.preview}
                                    </div>
                                </div>
                            )}
                        </div>
                    )}

                    {activeTab === 'bulk_meta' && (
                        <div className="space-y-6">
                            <div className="flex justify-between items-center">
                                <div>
                                    <h2 className="text-xl font-semibold m-0 text-slate-800">Bulk Meta Editor</h2>
                                    <p className="text-slate-600 mt-1 mb-0">Rapidly audit and update SEO Titles and Meta Descriptions.</p>
                                </div>
                                <Button 
                                    isPrimary 
                                    isBusy={isSavingBulkMeta} 
                                    disabled={Object.keys(bulkMetaEdits).length === 0 || isSavingBulkMeta}
                                    onClick={saveBulkMetaEdits}
                                >
                                    Save {Object.keys(bulkMetaEdits).length} Changes
                                </Button>
                            </div>

                            {bulkMetaStatus && <Notice status={bulkMetaStatus.type} onRemove={() => setBulkMetaStatus(null)}>{bulkMetaStatus.message}</Notice>}

                            <div className="flex gap-4 items-end bg-white p-4 border rounded shadow-sm">
                                <div>
                                    <label className="block text-xs font-semibold text-slate-700 mb-1">Post Type</label>
                                    <select 
                                        value={bulkMetaParams.post_type} 
                                        onChange={(e) => setBulkMetaParams({ ...bulkMetaParams, post_type: e.target.value, page: 1 })}
                                        className="p-2 border rounded"
                                    >
                                        {bulkMeta.post_types.map(t => (
                                            <option key={t.name} value={t.name}>{t.label}</option>
                                        ))}
                                    </select>
                                </div>
                                <div className="flex-1">
                                    <label className="block text-xs font-semibold text-slate-700 mb-1">Search</label>
                                    <input 
                                        type="text" 
                                        value={bulkMetaParams.search}
                                        onChange={(e) => setBulkMetaParams({ ...bulkMetaParams, search: e.target.value })}
                                        onKeyDown={(e) => e.key === 'Enter' && fetchBulkMeta()}
                                        placeholder="Search titles..." 
                                        className="p-2 border rounded w-full" 
                                    />
                                </div>
                                <Button isSecondary onClick={() => fetchBulkMeta()}>Filter</Button>
                            </div>

                            {isLoadingBulkMeta ? (
                                <div className="text-center p-8"><Spinner /></div>
                            ) : (
                                <div className="bg-white border rounded shadow-sm overflow-hidden">
                                    <table className="w-full text-left border-collapse">
                                        <thead>
                                            <tr className="bg-slate-50 border-b">
                                                <th className="p-3 text-sm font-semibold text-slate-700 w-1/4">Post / Page</th>
                                                <th className="p-3 text-sm font-semibold text-slate-700 w-1/3">SEO Title</th>
                                                <th className="p-3 text-sm font-semibold text-slate-700">Meta Description</th>
                                                <th className="p-3 text-sm font-semibold text-slate-700 w-24">NoIndex</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y">
                                            {bulkMeta.items.map(item => {
                                                const editedTitle = bulkMetaEdits[item.id]?.seo_title ?? item.seo_title;
                                                const editedDesc = bulkMetaEdits[item.id]?.seo_desc ?? item.seo_desc;
                                                const editedNoIndex = bulkMetaEdits[item.id]?.noindex ?? item.noindex;
                                                
                                                return (
                                                    <tr key={item.id} className="hover:bg-slate-50">
                                                        <td className="p-3 align-top">
                                                            <div className="font-semibold text-brand-600 text-sm mb-1">{item.title}</div>
                                                            <div className="text-xs text-slate-400">ID: {item.id} • {item.modified}</div>
                                                            <a href={item.url} target="_blank" rel="noreferrer" className="text-xs text-blue-500 hover:underline">View ›</a>
                                                        </td>
                                                        <td className="p-3 align-top">
                                                            <textarea 
                                                                value={editedTitle}
                                                                onChange={(e) => handleBulkMetaEdit(item.id, 'seo_title', e.target.value)}
                                                                className="w-full p-2 border rounded text-sm text-slate-700" 
                                                                rows="2" 
                                                                placeholder="Optimized Title..."
                                                            />
                                                            <div className={`text-xs mt-1 text-right ${editedTitle.length > 60 ? 'text-red-500' : 'text-slate-400'}`}>
                                                                {editedTitle.length} / 60
                                                            </div>
                                                        </td>
                                                        <td className="p-3 align-top">
                                                            <textarea 
                                                                value={editedDesc}
                                                                onChange={(e) => handleBulkMetaEdit(item.id, 'seo_desc', e.target.value)}
                                                                className="w-full p-2 border rounded text-sm text-slate-700" 
                                                                rows="3" 
                                                                placeholder="Meta description..."
                                                            />
                                                            <div className={`text-xs mt-1 text-right ${editedDesc.length > 160 ? 'text-red-500' : 'text-slate-400'}`}>
                                                                {editedDesc.length} / 160
                                                            </div>
                                                        </td>
                                                        <td className="p-3 align-top text-center">
                                                            <input 
                                                                type="checkbox" 
                                                                checked={editedNoIndex}
                                                                onChange={(e) => handleBulkMetaEdit(item.id, 'noindex', e.target.checked)}
                                                            />
                                                        </td>
                                                    </tr>
                                                );
                                            })}
                                            {bulkMeta.items.length === 0 && (
                                                <tr><td colSpan="4" className="text-center p-8 text-slate-500">No content found.</td></tr>
                                            )}
                                        </tbody>
                                    </table>
                                    
                                    {/* Pagination */}
                                    {bulkMeta.pages > 1 && (
                                        <div className="flex justify-between items-center p-4 border-t bg-slate-50">
                                            <Button 
                                                isSecondary 
                                                disabled={bulkMeta.page <= 1}
                                                onClick={() => setBulkMetaParams({ ...bulkMetaParams, page: bulkMeta.page - 1 })}
                                            >
                                                « Previous
                                            </Button>
                                            <span className="text-sm font-medium">Page {bulkMeta.page} of {bulkMeta.pages}</span>
                                            <Button 
                                                isSecondary 
                                                disabled={bulkMeta.page >= bulkMeta.pages}
                                                onClick={() => setBulkMetaParams({ ...bulkMetaParams, page: bulkMeta.page + 1 })}
                                            >
                                                Next »
                                            </Button>
                                        </div>
                                    )}
                                </div>
                            )}
                        </div>
                    )}


                    {/* ══════════════ IMAGE SEO TAB ══════════════ */}
                    {activeTab === 'image_seo' && (
                        <div className="space-y-8 max-w-4xl">
                            <div>
                                <h2 className="text-xl font-semibold m-0 text-slate-800">Image SEO</h2>
                                <p className="text-slate-500 text-sm mt-1">Create reversible JPEG and PNG optimizations, then audit missing alt text across your media library.</p>
                            </div>

                            {/* Compression Settings */}
                            <div className="bg-white border rounded-lg p-6 space-y-4">
                                <h3 className="text-base font-semibold text-slate-800 m-0">Reversible Image Compression</h3>
                                {isLoadingCompression && <div className="flex items-center gap-3"><Spinner /><span className="text-slate-500">Loading…</span></div>}
                                {compressionStats && (
                                    <div className="grid grid-cols-3 gap-4 mb-4">
                                        <div className="bg-slate-50 p-4 rounded-lg text-center">
                                            <div className="text-2xl font-bold text-slate-800">{compressionStats.total}</div>
                                            <div className="text-xs text-slate-500 mt-1">Total Images</div>
                                        </div>
                                        <div className="bg-green-50 p-4 rounded-lg text-center">
                                            <div className="text-2xl font-bold text-green-700">{compressionStats.compressed}</div>
                                            <div className="text-xs text-slate-500 mt-1">Compressed</div>
                                        </div>
                                        <div className="bg-amber-50 p-4 rounded-lg text-center">
                                            <div className="text-2xl font-bold text-amber-700">{compressionStats.uncompressed}</div>
                                            <div className="text-xs text-slate-500 mt-1">Pending</div>
                                        </div>
                                    </div>
                                )}
                                <div className="flex items-center gap-6 flex-wrap">
                                    <label className="flex items-center gap-2 cursor-pointer">
                                        <input type="checkbox" checked={compressionConfig.enabled} onChange={e => setCompressionConfig({...compressionConfig, enabled: e.target.checked})} />
                                        <span className="text-sm font-medium">Enable reversible bulk compression</span>
                                    </label>
                                </div>
                                <p className="text-xs text-slate-500">New uploads are never changed automatically. Each processed image retains its original file until you restore or delete it.</p>
                                {compressionConfig.enabled && (
                                    <label className="flex items-start gap-2 text-sm text-slate-700 bg-amber-50 border border-amber-200 rounded p-3">
                                        <input type="checkbox" checked={compressionAcknowledged} onChange={e => setCompressionAcknowledged(e.target.checked)} className="mt-1" />
                                        <span>I understand that compression changes the displayed media file, and that Holvek will retain an original backup so I can restore it.</span>
                                    </label>
                                )}
                                <div className="flex items-center gap-4">
                                    <label className="text-sm font-medium text-slate-700">Quality: <strong>{compressionConfig.quality}</strong></label>
                                    <input type="range" min="40" max="95" value={compressionConfig.quality} onChange={e => setCompressionConfig({...compressionConfig, quality: Number(e.target.value)})} className="flex-1 max-w-xs" />
                                    <span className="text-xs text-slate-400">40 = smaller · 95 = higher quality</span>
                                </div>
                                {compressionStats && <p className="text-xs text-slate-400">Engine: <strong>{compressionStats.engine}</strong> · WebP generation is unavailable until safe delivery support ships.</p>}
                                {compressionMsg && <p className={`text-sm ${compressionMsg.type === 'success' ? 'text-green-700' : 'text-red-600'}`}>{compressionMsg.text}</p>}
                                <div className="flex gap-3 pt-2">
                                    <button type="button" onClick={saveCompressionConfig} disabled={isSavingCompression} className="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded hover:bg-indigo-700 disabled:opacity-50">
                                        {isSavingCompression ? 'Saving…' : 'Save Settings'}
                                    </button>
                                    {compressionStats && compressionStats.uncompressed > 0 && (
                                        <button type="button" onClick={runBulkCompress} disabled={isBulkCompressing || !compressionConfig.enabled} className="px-4 py-2 bg-emerald-600 text-white text-sm font-medium rounded hover:bg-emerald-700 disabled:opacity-50">
                                            {isBulkCompressing ? `Compressing… (${compressionProgress ? compressionProgress.processed : 0} done, ${compressionProgress ? compressionProgress.remaining : '?'} remaining)` : `Compress ${compressionStats.uncompressed} Pending Images`}
                                        </button>
                                    )}
                                </div>
                                {compressionBackups.length > 0 && (
                                    <div className="border-t pt-4 space-y-2">
                                        <h4 className="text-sm font-semibold text-slate-800 m-0">Restorable originals</h4>
                                        <p className="text-xs text-slate-500 m-0">Restore returns the attachment to its exact pre-compression file.</p>
                                        <ul className="m-0 p-0 list-none space-y-2">
                                            {compressionBackups.map((item) => (
                                                <li key={item.attachment_id} className="flex items-center justify-between gap-3 text-sm border rounded p-2">
                                                    <a href={item.url} target="_blank" rel="noreferrer" className="truncate text-indigo-700">{item.title || `Attachment #${item.attachment_id}`}</a>
                                                    <button type="button" onClick={() => restoreCompressedImage(item.attachment_id)} className="shrink-0 px-3 py-1 border border-slate-300 rounded text-slate-700 hover:bg-slate-50">Restore original</button>
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                )}
                            </div>

                            {/* Alt Text Audit */}
                            <div className="bg-white border rounded-lg p-6 space-y-4">
                                <div className="flex justify-between items-center">
                                    <div>
                                        <h3 className="text-base font-semibold text-slate-800 m-0">Alt Text Audit</h3>
                                        <p className="text-sm text-slate-500 mt-1">{altAuditTotal} images in your media library are missing alt text.</p>
                                    </div>
                                    <div className="flex gap-2">
                                        <button type="button" onClick={autoFillAltText} disabled={isAutoFillingAlt} className="px-3 py-1.5 bg-amber-500 text-white text-xs font-semibold rounded hover:bg-amber-600 disabled:opacity-50">
                                            {isAutoFillingAlt ? 'Auto-filling…' : '✨ Auto-fill from Filenames'}
                                        </button>
                                        {Object.keys(altEdits).length > 0 && (
                                            <button type="button" onClick={saveAltEdits} className="px-3 py-1.5 bg-indigo-600 text-white text-xs font-semibold rounded hover:bg-indigo-700">
                                                Save {Object.keys(altEdits).length} Edits
                                            </button>
                                        )}
                                    </div>
                                </div>
                                {altAuditMsg && <p className={`text-sm ${altAuditMsg.type === 'success' ? 'text-green-700' : 'text-red-600'}`}>{altAuditMsg.text}</p>}
                                {isLoadingAltAudit ? (
                                    <div className="flex items-center gap-3 p-4"><Spinner /><span className="text-slate-500">Loading images…</span></div>
                                ) : (
                                    <div className="overflow-hidden border rounded">
                                        <table className="w-full text-sm text-left">
                                            <thead><tr className="bg-slate-50 border-b"><th className="p-3 font-semibold text-slate-600">Image</th><th className="p-3 font-semibold text-slate-600">Filename</th><th className="p-3 font-semibold text-slate-600">Alt Text</th></tr></thead>
                                            <tbody>
                                                {altAuditItems.map(item => (
                                                    <tr key={item.id} className="border-b hover:bg-slate-50">
                                                        <td className="p-3 w-16">{item.thumb && <img src={item.thumb} alt="" className="w-12 h-12 object-cover rounded" />}</td>
                                                        <td className="p-3 text-xs text-slate-500 w-48">{item.filename}</td>
                                                        <td className="p-3">
                                                            <input
                                                                type="text"
                                                                value={altEdits[item.id] !== undefined ? altEdits[item.id] : (item.alt || '')}
                                                                onChange={e => setAltEdits(prev => ({ ...prev, [item.id]: e.target.value }))}
                                                                placeholder="Describe this image…"
                                                                className="w-full p-1.5 border border-slate-300 rounded text-sm"
                                                            />
                                                        </td>
                                                    </tr>
                                                ))}
                                                {altAuditItems.length === 0 && (
                                                    <tr><td colSpan="3" className="text-center p-8 text-slate-500">🎉 All images have alt text!</td></tr>
                                                )}
                                            </tbody>
                                        </table>
                                        {altAuditTotal > 20 && (
                                            <div className="flex justify-between items-center p-3 border-t bg-slate-50">
                                                <button type="button" onClick={() => loadAltAudit(altAuditPage - 1)} disabled={altAuditPage <= 1} className="px-3 py-1 text-xs border rounded disabled:opacity-40">« Prev</button>
                                                <span className="text-xs text-slate-500">Page {altAuditPage} · {altAuditTotal} total</span>
                                                <button type="button" onClick={() => loadAltAudit(altAuditPage + 1)} disabled={altAuditItems.length < 20} className="px-3 py-1 text-xs border rounded disabled:opacity-40">Next »</button>
                                            </div>
                                        )}
                                    </div>
                                )}
                            </div>
                        </div>
                    )}

                    {/* ══════════════ COMPLIANCE TAB ══════════════ */}
                    {activeTab === 'compliance' && (
                        <div className="space-y-6 max-w-3xl">
                            <div>
                                <h2 className="text-xl font-semibold m-0 text-slate-800">Cookie & Compliance</h2>
                                <p className="text-slate-500 text-sm mt-1">Configure a lightweight cookie notice. It records a visitor’s choice but does not block or manage third-party scripts.</p>
                            </div>
                            {isLoadingCookieBanner ? (
                                <div className="flex items-center gap-3 p-6"><Spinner /><span className="text-slate-500">Loading settings…</span></div>
                            ) : (
                                <>
                                <div className="bg-white border rounded-lg p-6 space-y-5">
                                    <div className="flex items-center justify-between">
                                        <div>
                                            <p className="font-semibold text-slate-800 m-0">Enable Cookie Banner</p>
                                            <p className="text-xs text-slate-500 mt-0.5">Show a consent bar to visitors on the front end.</p>
                                        </div>
                                        <input type="checkbox" checked={cookieBanner.enabled} onChange={e => setCookieBanner({...cookieBanner, enabled: e.target.checked})} className="w-5 h-5 cursor-pointer" />
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-slate-700 mb-1">Banner Message</label>
                                        <textarea value={cookieBanner.message} onChange={e => setCookieBanner({...cookieBanner, message: e.target.value})} rows={3} className="w-full p-2 border border-slate-300 rounded text-sm" />
                                    </div>
                                    <div className="grid grid-cols-2 gap-4">
                                        <div>
                                            <label className="block text-xs font-semibold text-slate-700 mb-1">Accept Button Label</label>
                                            <input type="text" value={cookieBanner.accept_label} onChange={e => setCookieBanner({...cookieBanner, accept_label: e.target.value})} className="w-full p-2 border border-slate-300 rounded text-sm" />
                                        </div>
                                        <div>
                                            <label className="block text-xs font-semibold text-slate-700 mb-1">Decline Button Label</label>
                                            <input type="text" value={cookieBanner.decline_label} onChange={e => setCookieBanner({...cookieBanner, decline_label: e.target.value})} className="w-full p-2 border border-slate-300 rounded text-sm" />
                                        </div>
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-slate-700 mb-1">Privacy Policy URL</label>
                                        <input type="url" value={cookieBanner.policy_url} onChange={e => setCookieBanner({...cookieBanner, policy_url: e.target.value})} placeholder="https://example.com/privacy-policy" className="w-full p-2 border border-slate-300 rounded text-sm" />
                                    </div>
                                    {cookieBannerMsg && <p className={`text-sm ${cookieBannerMsg.type === 'success' ? 'text-green-700' : 'text-red-600'}`}>{cookieBannerMsg.text}</p>}
                                    <button type="button" onClick={saveCookieBanner} disabled={isSavingCookieBanner} className="px-5 py-2 bg-indigo-600 text-white text-sm font-medium rounded hover:bg-indigo-700 disabled:opacity-50">
                                        {isSavingCookieBanner ? 'Saving…' : 'Save Cookie Banner Settings'}
                                    </button>
                                </div>
                                <div className="mt-8 bg-slate-50 border border-slate-200 rounded-lg p-6">
                                    <h3 className="text-lg font-semibold text-slate-800 m-0">Third-Party Service Disclosures</h3>
                                    <p className="text-slate-600 text-sm mt-2 mb-4">
                                        Holvek integrates with external APIs to provide advanced features. You should update your Privacy Policy if you use these features.
                                    </p>
                                    <ul className="list-disc pl-5 text-sm text-slate-600 space-y-2">
                                        <li><strong>OpenAI / Anthropic:</strong> Receives post excerpts when using the LLM Writing Assistant.</li>
                                        <li><strong>Google APIs:</strong> Receives URLs for PageSpeed Insights and Indexing API if enabled.</li>
                                        <li><strong>Bing IndexNow:</strong> Receives URLs automatically when posts are published to accelerate crawling.</li>
                                        <li><strong>SerpApi:</strong> Receives your focus keywords for rank tracking.</li>
                                    </ul>
                                    <p className="text-xs text-slate-500 mt-4">
                                        <em>A complete template has been automatically added to your <a href={holvekAdminConfig.apiUrl.replace('wp-json/holvek/v1', 'wp-admin/privacy.php')} className="text-indigo-600 underline">WordPress Privacy Policy Guide</a>.</em>
                                    </p>
                                </div>
                                </>
                            )}
                        </div>
                    )}
                    {/* ══════════════ ROLES & CAPABILITIES TAB ══════════════ */}
                    {activeTab === 'roles' && (
                        <div className="space-y-6 max-w-4xl">
                            <div>
                                <h2 className="text-xl font-semibold m-0 text-slate-800">Roles & Capabilities</h2>
                                <p className="text-slate-500 text-sm mt-1">Control which WordPress roles can access Holvek features. Administrators always retain full access.</p>
                            </div>

                            {rolesLoading && <div className="flex items-center gap-3 p-6 bg-slate-50 rounded-lg"><Spinner /><span className="text-slate-500">Loading roles…</span></div>}

                            {rolesData && (
                                <div className="bg-white border border-slate-200 rounded-lg overflow-hidden">
                                    {/* Capability legend */}
                                    <div className="px-6 py-4 bg-slate-50 border-b border-slate-200 grid grid-cols-4 gap-4 text-xs font-semibold text-slate-600">
                                        <div className="col-span-4 grid grid-cols-5 gap-2 items-center">
                                            <span className="text-slate-500 font-medium">Role</span>
                                            {rolesData.seo_capabilities.map(cap => (
                                                <span key={cap} className="text-center text-xs font-mono bg-slate-200 text-slate-700 px-2 py-1 rounded">{cap.replace(/_/g, ' ')}</span>
                                            ))}
                                        </div>
                                    </div>

                                    {/* Role rows */}
                                    <div className="divide-y divide-slate-100">
                                        {Object.entries(rolesData.roles).map(([slug, role]) => {
                                            const isAdmin = slug === 'administrator';
                                            return (
                                                <div key={slug} className={`px-6 py-4 grid grid-cols-5 gap-2 items-center ${isAdmin ? 'bg-indigo-50' : 'hover:bg-slate-50'}`}>
                                                    <div>
                                                        <span className="text-sm font-semibold text-slate-800">{role.label}</span>
                                                        {isAdmin && <span className="ml-2 text-xs text-indigo-600 bg-indigo-100 px-1.5 py-0.5 rounded">Full Access</span>}
                                                    </div>
                                                    {rolesData.seo_capabilities.map(cap => {
                                                        const granted = isAdmin ? true : !!(roleEdits[slug]?.[cap]);
                                                        return (
                                                            <div key={cap} className="flex justify-center">
                                                                <input
                                                                    type="checkbox"
                                                                    checked={granted}
                                                                    disabled={isAdmin}
                                                                    onChange={e => toggleCap(slug, cap, e.target.checked)}
                                                                    className="w-4 h-4 rounded border-slate-300 text-indigo-600 cursor-pointer disabled:cursor-not-allowed disabled:opacity-60"
                                                                />
                                                            </div>
                                                        );
                                                    })}
                                                </div>
                                            );
                                        })}
                                    </div>

                                    {/* Capability descriptions */}
                                    <div className="px-6 py-4 bg-slate-50 border-t border-slate-200 grid grid-cols-2 gap-3 text-xs text-slate-500">
                                        <div>🛡 <strong className="text-slate-700">manage_seo</strong> — Full access to all Holvek settings, integrations, and configuration</div>
                                        <div>📊 <strong className="text-slate-700">view_seo_reports</strong> — Read-only access to GSC dashboard, GA4 analytics, link health reports</div>
                                        <div>↩️ <strong className="text-slate-700">manage_redirects</strong> — Create, edit, and delete redirect rules; import/export CSV</div>
                                        <div>✏️ <strong className="text-slate-700">edit_seo_meta</strong> — Edit per-post SEO meta title, description, canonical, OG fields</div>
                                    </div>
                                </div>
                            )}

                            {rolesData && (
                                <div className="flex items-center gap-3">
                                    {rolesMsg && <span className={`text-sm px-3 py-1.5 rounded ${rolesMsg.type === 'success' ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800'}`}>{rolesMsg.text}</span>}
                                    <button type="button" onClick={saveRoles} disabled={rolesSaving} className="px-5 py-2 bg-indigo-600 text-white text-sm font-medium rounded hover:bg-indigo-700 disabled:opacity-50">
                                        {rolesSaving ? 'Saving…' : 'Save Capabilities'}
                                    </button>
                                    <button type="button" onClick={loadRoles} className="px-4 py-2 bg-slate-100 text-slate-700 text-sm rounded hover:bg-slate-200">↺ Reset</button>
                                </div>
                            )}
                        </div>
                    )}

                    {/* ══════════════ NETWORK SEO TAB (Multisite only) ══════════════ */}
                    {activeTab === 'network_seo' && isMultisite && (
                        <div className="space-y-6 max-w-4xl">
                            <div>
                                <h2 className="text-xl font-semibold m-0 text-slate-800">Network SEO</h2>
                                <p className="text-slate-500 text-sm mt-1">Configure network-wide SEO defaults for your WordPress Multisite. Individual sites can override these when allowed.</p>
                            </div>

                            {networkLoading && <div className="flex items-center gap-3 p-6 bg-slate-50 rounded-lg"><Spinner /><span className="text-slate-500">Loading network data…</span></div>}

                            {networkSettings && (
                                <div className="grid grid-cols-5 gap-6">
                                    {/* Settings panel — left 3/5 */}
                                    <div className="col-span-3 space-y-5">
                                        <div className="bg-white border border-slate-200 rounded-lg p-6 space-y-5">
                                            <h3 className="text-sm font-semibold text-slate-700 m-0 border-b border-slate-200 pb-3">Network Defaults</h3>

                                            <div>
                                                <label className="block text-xs font-semibold text-slate-600 mb-1">Default Schema Type</label>
                                                <select value={networkSettings.default_schema_type} onChange={e => setNetworkSettings(s => ({ ...s, default_schema_type: e.target.value }))} className="w-full p-2 border border-slate-300 rounded text-sm bg-white">
                                                    {['Article', 'WebPage', 'BlogPosting', 'NewsArticle', 'FAQPage', 'LocalBusiness', 'Organization', 'Product'].map(t => (
                                                        <option key={t} value={t}>{t}</option>
                                                    ))}
                                                </select>
                                            </div>

                                            <div>
                                                <label className="block text-xs font-semibold text-slate-600 mb-1">Shared IndexNow API Key</label>
                                                <input type="text" value={networkSettings.shared_indexnow_key} onChange={e => setNetworkSettings(s => ({ ...s, shared_indexnow_key: e.target.value }))} placeholder="Shared across all network sites" className="w-full p-2 border border-slate-300 rounded text-sm" />
                                                <p className="text-xs text-slate-400 mt-1">Leave blank to let each site use its own key.</p>
                                            </div>

                                            <div>
                                                <label className="block text-xs font-semibold text-slate-600 mb-1">Network-wide robots.txt Rules</label>
                                                <textarea value={networkSettings.network_robots_rules} onChange={e => setNetworkSettings(s => ({ ...s, network_robots_rules: e.target.value }))} rows={5} placeholder="# Rules appended to every site's robots.txt&#10;User-agent: *&#10;Disallow: /wp-admin/" className="w-full p-2 border border-slate-300 rounded text-sm font-mono text-xs" />
                                            </div>

                                            <div className="space-y-3 pt-2 border-t border-slate-200">
                                                {[
                                                    ['force_ssl', 'Force HTTPS across all sites', 'Adds HSTS headers and redirects HTTP to HTTPS network-wide'],
                                                    ['block_ai_bots', 'Block AI bots network-wide', 'Applies the Holvek AI bot firewall to every site in the network'],
                                                    ['allow_site_overrides', 'Allow site admins to override network defaults', 'When enabled, individual site admins can customize their own SEO settings'],
                                                ].map(([key, label, help]) => (
                                                    <label key={key} className="flex items-start gap-3 cursor-pointer">
                                                        <input type="checkbox" checked={!!networkSettings[key]} onChange={e => setNetworkSettings(s => ({ ...s, [key]: e.target.checked }))} className="mt-0.5 w-4 h-4 rounded border-slate-300 text-indigo-600" />
                                                        <div>
                                                            <span className="text-sm font-medium text-slate-800">{label}</span>
                                                            <p className="text-xs text-slate-400 m-0 mt-0.5">{help}</p>
                                                        </div>
                                                    </label>
                                                ))}
                                            </div>

                                            {networkMsg && <div className={`text-sm px-3 py-2 rounded ${networkMsg.type === 'success' ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800'}`}>{networkMsg.text}</div>}
                                            <button type="button" onClick={saveNetworkSettings} disabled={networkSaving} className="px-5 py-2 bg-indigo-600 text-white text-sm font-medium rounded hover:bg-indigo-700 disabled:opacity-50">
                                                {networkSaving ? 'Saving…' : 'Save Network Settings'}
                                            </button>
                                        </div>
                                    </div>

                                    {/* Sites health panel — right 2/5 */}
                                    <div className="col-span-2">
                                        <div className="bg-white border border-slate-200 rounded-lg overflow-hidden">
                                            <div className="px-4 py-3 border-b border-slate-200 bg-slate-50 flex items-center justify-between">
                                                <span className="text-sm font-semibold text-slate-700">Network Sites</span>
                                                <span className="text-xs text-slate-400">{networkSites.length} sites</span>
                                            </div>
                                            <div className="divide-y divide-slate-100 max-h-[500px] overflow-y-auto">
                                                {networkSites.map(site => (
                                                    <div key={site.blog_id} className="px-4 py-3 hover:bg-slate-50">
                                                        <div className="text-sm font-medium text-slate-800 truncate">{site.name || site.domain}</div>
                                                        <div className="text-xs text-slate-400 truncate">{site.domain}</div>
                                                        <div className="flex items-center gap-3 mt-1">
                                                            <span className="text-xs text-slate-500">{site.post_count ?? '—'} posts</span>
                                                            <span className="text-xs text-slate-400">{site.admin_email}</span>
                                                        </div>
                                                    </div>
                                                ))}
                                                {networkSites.length === 0 && <div className="p-8 text-center text-slate-400 text-sm">No sites found.</div>}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            )}
                        </div>
                    )}

                    {/* ══════════════ SITE AUDIT TAB ══════════════ */}
                    {activeTab === 'site_audit' && (
                        <div className="space-y-6 max-w-4xl">
                            <div className="flex items-start justify-between">
                                <div>
                                    <h2 className="text-xl font-semibold m-0 text-slate-800">Technical SEO Audit</h2>
                                    <p className="text-slate-500 text-sm mt-1">{__('This audit runs on this site. It checks meta descriptions, image alt text, 404s, redirect chains, orphaned pages, noindex directives, the sitemap, robots.txt, the site icon, SSL, schema, duplicate titles, cornerstone staleness, and canonicals. It does not score AI mentions, Google presence, or competitors.', 'holvek')}</p>
                                </div>
                                <button type="button" onClick={runSiteAudit} disabled={auditRunning} className="px-4 py-2 bg-indigo-600 text-white text-sm font-semibold rounded hover:bg-indigo-700 disabled:opacity-50 whitespace-nowrap">
                                    {auditRunning ? '⏳ Running…' : '▶ Run Audit'}
                                </button>
                            </div>

                            {/* Progress bar */}
                            {auditRunning && (
                                <div className="bg-white border border-slate-200 rounded-lg p-5 space-y-3">
                                    <div className="flex justify-between text-sm">
                                        <span className="text-slate-600 font-medium">{auditProgress.current_check}</span>
                                        <span className="text-slate-400">{auditProgress.percent}%</span>
                                    </div>
                                    <div className="h-2 bg-slate-100 rounded-full overflow-hidden">
                                        <div className="h-full bg-indigo-500 rounded-full transition-all duration-500" style={{ width: `${auditProgress.percent}%` }} />
                                    </div>
                                </div>
                            )}

                            {/* Summary badges */}
                            {auditResults?.summary && (
                                <div className="grid grid-cols-3 gap-4">
                                    {[
                                        { label: 'Critical', count: auditResults.summary.critical, color: 'red' },
                                        { label: 'Warnings', count: auditResults.summary.warnings, color: 'amber' },
                                        { label: 'Passed',   count: auditResults.summary.passed,   color: 'green' },
                                    ].map(({ label, count, color }) => (
                                        <div key={label} className={`bg-${color}-50 border border-${color}-200 rounded-lg p-4 text-center`}>
                                            <div className={`text-3xl font-bold text-${color}-700`}>{count}</div>
                                            <div className={`text-xs text-${color}-600 mt-1`}>{label}</div>
                                        </div>
                                    ))}
                                </div>
                            )}

                            {/* Checks list */}
                            {auditResults?.checks && (
                                <div className="bg-white border border-slate-200 rounded-lg overflow-hidden divide-y divide-slate-100">
                                    {auditResults.checks.map(check => {
                                        const icon = check.status === 'critical' ? '🔴' : check.status === 'warning' ? '🟡' : check.status === 'passed' ? '🟢' : 'ℹ️';
                                        const bg   = check.status === 'critical' ? 'bg-red-50' : check.status === 'warning' ? 'bg-amber-50' : '';
                                        return (
                                            <div key={check.id} className={`px-5 py-4 ${bg}`}>
                                                <div className="flex items-start justify-between gap-4">
                                                    <div className="flex items-start gap-3 flex-1">
                                                        <span className="text-lg mt-0.5">{icon}</span>
                                                        <div>
                                                            <div className="text-sm font-semibold text-slate-800">{check.name}</div>
                                                            <div className="text-sm text-slate-500 mt-0.5">{check.message}</div>
                                                            {check.items?.length > 0 && check.status !== 'passed' && (
                                                                <ul className="mt-2 space-y-1">
                                                                    {check.items.slice(0, 5).map((item, i) => (
                                                                        <li key={i} className="text-xs text-slate-500 font-mono bg-slate-100 px-2 py-1 rounded">
                                                                            {item.title || item.source || item.issue || item.message || JSON.stringify(item).slice(0, 100)}
                                                                        </li>
                                                                    ))}
                                                                    {check.items.length > 5 && <li className="text-xs text-slate-400">…and {check.items.length - 5} more</li>}
                                                                </ul>
                                                            )}
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            )}

                            {!auditResults && !auditRunning && (
                                <div className="bg-slate-50 border-2 border-dashed border-slate-200 rounded-lg p-12 text-center">
                                    <div className="text-4xl mb-3">🔍</div>
                                    <p className="text-slate-500 font-medium">No audit results yet.</p>
                                    <p className="text-sm text-slate-400 mt-1">Click "Run Audit" to scan your site. Runs in the background — results appear when complete.</p>
                                </div>
                            )}

                            {auditResults?.generated_at && (
                                <p className="text-xs text-slate-400 text-right">Last run: {auditResults.generated_at}</p>
                            )}
                        </div>
                    )}

                    {/* ══════════════ BROKEN LINKS TAB ══════════════ */}
                    {activeTab === 'broken_links' && (
                        <div className="space-y-6 max-w-5xl">
                            <div className="flex items-start justify-between">
                                <div>
                                    <h2 className="text-xl font-semibold m-0 text-slate-800">Broken Link Checker</h2>
                                    <p className="text-slate-500 text-sm mt-1">Scan all published posts and pages for dead outbound links. Batched across background jobs — never slows your server.</p>
                                </div>
                                <button type="button" onClick={startBlcScan} disabled={blcScanning} className="px-4 py-2 bg-indigo-600 text-white text-sm font-semibold rounded hover:bg-indigo-700 disabled:opacity-50 whitespace-nowrap">
                                    {blcScanning ? '⏳ Scanning…' : '▶ Start Scan'}
                                </button>
                            </div>

                            {/* Progress / status bar */}
                            {blcProgress.status !== 'idle' && (
                                <div className="bg-white border border-slate-200 rounded-lg p-4 flex items-center gap-6 flex-wrap text-sm">
                                    <span className={`font-semibold ${blcProgress.status === 'done' ? 'text-green-700' : 'text-indigo-700'}`}>
                                        {blcProgress.status === 'done' ? '✅ Scan complete' : '⏳ Scanning…'}
                                    </span>
                                    <span className="text-slate-500">Scanned: <strong>{blcProgress.scanned}</strong> / {blcProgress.total}</span>
                                    <span className="text-red-600">Broken: <strong>{blcProgress.found}</strong></span>
                                    {blcProgress.status !== 'done' && blcProgress.total > 0 && (
                                        <div className="flex-1 h-2 bg-slate-100 rounded-full overflow-hidden min-w-[120px]">
                                            <div className="h-full bg-indigo-500 rounded-full transition-all" style={{ width: `${Math.round((blcProgress.scanned / blcProgress.total) * 100)}%` }} />
                                        </div>
                                    )}
                                </div>
                            )}

                            {blcMsg && <div className={`text-sm px-3 py-2 rounded ${blcMsg.type === 'success' ? 'bg-green-50 text-green-800' : blcMsg.type === 'error' ? 'bg-red-50 text-red-800' : 'bg-blue-50 text-blue-800'}`}>{blcMsg.text}</div>}

                            {/* Filter + table */}
                            <div className="bg-white border border-slate-200 rounded-lg overflow-hidden">
                                <div className="px-5 py-3 border-b border-slate-200 flex items-center justify-between">
                                    <span className="text-sm font-semibold text-slate-700">{blcShowResolved ? 'Resolved Links' : 'Broken Links'} <span className="text-slate-400 font-normal">({blcTotal})</span></span>
                                    <button type="button" onClick={() => { const r = !blcShowResolved; setBlcShowResolved(r); loadBlcResults(1, r); }} className="text-xs text-indigo-600 hover:underline">
                                        {blcShowResolved ? 'Show broken' : 'Show resolved'}
                                    </button>
                                </div>
                                {blcLoading ? (
                                    <div className="flex items-center gap-3 p-8"><Spinner /><span className="text-slate-500">Loading…</span></div>
                                ) : (
                                    <table className="w-full text-sm text-left">
                                        <thead><tr className="bg-slate-50 border-b border-slate-200">
                                            <th className="p-3 font-semibold text-slate-600 w-16">Code</th>
                                            <th className="p-3 font-semibold text-slate-600">Broken URL</th>
                                            <th className="p-3 font-semibold text-slate-600">Found In</th>
                                            <th className="p-3 font-semibold text-slate-600">Anchor</th>
                                            <th className="p-3 font-semibold text-slate-600 w-32">Actions</th>
                                        </tr></thead>
                                        <tbody className="divide-y divide-slate-100">
                                            {blcResults.map(row => (
                                                <tr key={row.id} className="hover:bg-slate-50">
                                                    <td className="p-3"><span className={`px-2 py-0.5 rounded text-xs font-bold ${Number(row.http_code) >= 500 ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700'}`}>{row.http_code ?? '?'}</span></td>
                                                    <td className="p-3 max-w-xs"><a href={row.url} target="_blank" rel="noopener noreferrer" className="text-indigo-600 hover:underline truncate block" title={row.url}>{row.url}</a></td>
                                                    <td className="p-3 text-xs text-slate-500"><a href={row.edit_url} target="_blank" rel="noopener noreferrer" className="hover:underline">{row.post_title || `#${row.post_id}`}</a></td>
                                                    <td className="p-3 text-xs text-slate-400 truncate max-w-[120px]">{row.anchor_text || '—'}</td>
                                                    <td className="p-3">
                                                        <div className="flex gap-1.5">
                                                            <button type="button" onClick={() => recheckBlcLink(row.id, row.url)} className="px-2 py-1 text-xs bg-slate-100 hover:bg-slate-200 rounded">↻</button>
                                                            <button type="button" onClick={() => resolveBlcLink(row.id)} className="px-2 py-1 text-xs bg-green-100 hover:bg-green-200 text-green-700 rounded">✓</button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            ))}
                                            {blcResults.length === 0 && (
                                                <tr><td colSpan="5" className="p-10 text-center text-slate-400">
                                                    {blcProgress.status === 'done' ? '🎉 No broken links found!' : 'Run a scan to detect broken links.'}
                                                </td></tr>
                                            )}
                                        </tbody>
                                    </table>
                                )}
                                {blcTotal > 20 && (
                                    <div className="flex justify-between items-center p-3 border-t bg-slate-50">
                                        <button type="button" onClick={() => loadBlcResults(blcPage - 1, blcShowResolved)} disabled={blcPage <= 1} className="px-3 py-1 text-xs border rounded disabled:opacity-40">« Prev</button>
                                        <span className="text-xs text-slate-500">Page {blcPage} · {blcTotal} total</span>
                                        <button type="button" onClick={() => loadBlcResults(blcPage + 1, blcShowResolved)} disabled={blcResults.length < 20} className="px-3 py-1 text-xs border rounded disabled:opacity-40">Next »</button>
                                    </div>
                                )}
                            </div>
                        </div>
                    )}

                    {/* ══════════════ KEYWORD RANK TRACKER TAB ══════════════ */}
                    {activeTab === 'rank_tracker' && (
                        <div className="space-y-6 max-w-4xl">
                            <div>
                                <h2 className="text-xl font-semibold m-0 text-slate-800">Keyword Rank Tracker</h2>
                                <p className="text-slate-500 text-sm mt-1">Track your Google ranking positions over time. Checks run daily via SerpApi.</p>
                                {holvekAdminConfig.cloudConfigured !== '1' && (
                                    <p className="text-sm mt-3 mb-0 p-3 bg-slate-50 border border-slate-200 rounded text-slate-700">
                                        {__('This site is not connected to Holvek, so cloud rank tracking is unavailable. You can keep using Holvek on this site.', 'holvek')}
                                    </p>
                                )}
                            </div>

                            {/* SerpApi key notice */}
                            {!serpApiKey && (
                                <div className="p-4 bg-amber-50 border border-amber-200 rounded-lg text-amber-800 text-sm">
                                    ⚠ <strong>SerpApi key required.</strong> Add your <code className="font-mono text-xs">serpapi_key</code> in the Settings tab to enable rank checking.
                                    <a href="https://serpapi.com" target="_blank" rel="noopener" className="ml-2 underline">Get a free key →</a>
                                </div>
                            )}

                            <div className="grid grid-cols-5 gap-6">
                                {/* Keyword manager — left 2/5 */}
                                <div className="col-span-2 space-y-4">
                                    <div className="bg-white border border-slate-200 rounded-lg p-5 space-y-4">
                                        <h3 className="text-sm font-semibold text-slate-700 m-0">Tracked Keywords <span className="text-slate-400 font-normal">({rankKeywords.length}/100)</span></h3>

                                        <div className="flex gap-2">
                                            <input type="text" value={newKeyword} onChange={e => setNewKeyword(e.target.value)}
                                                onKeyDown={e => e.key === 'Enter' && addKeyword()}
                                                placeholder="Add keyword…" className="flex-1 p-2 border border-slate-300 rounded text-sm" />
                                            <button type="button" onClick={addKeyword} disabled={rankSaving} className="px-3 py-2 bg-indigo-600 text-white text-sm rounded hover:bg-indigo-700 disabled:opacity-50">+</button>
                                        </div>

                                        <div className="space-y-1 max-h-72 overflow-y-auto">
                                            {rankKeywords.map(kw => {
                                                const latest = rankHistory[kw]?.[0];
                                                const prev   = rankHistory[kw]?.[1];
                                                const delta  = latest && prev ? prev.position - latest.position : null;
                                                return (
                                                    <div key={kw} className="flex items-center justify-between px-3 py-2 bg-slate-50 rounded hover:bg-slate-100 group">
                                                        <div className="flex items-center gap-2">
                                                            <span className="text-sm text-slate-700 truncate max-w-[130px]">{kw}</span>
                                                            {latest?.position && (
                                                                <span className="text-xs font-bold text-slate-800">#{latest.position}</span>
                                                            )}
                                                            {delta !== null && (
                                                                <span className={`text-xs ${delta > 0 ? 'text-green-600' : delta < 0 ? 'text-red-600' : 'text-slate-400'}`}>
                                                                    {delta > 0 ? `▲${delta}` : delta < 0 ? `▼${Math.abs(delta)}` : '—'}
                                                                </span>
                                                            )}
                                                        </div>
                                                        <div className="flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                                            <button type="button" onClick={() => checkRankNow(kw)} disabled={rankChecking} title="Check now" className="px-1.5 py-0.5 text-xs bg-white border border-slate-200 rounded hover:bg-indigo-50">↻</button>
                                                            <button type="button" onClick={() => removeKeyword(kw)} title="Remove" className="px-1.5 py-0.5 text-xs bg-white border border-slate-200 rounded hover:bg-red-50 text-red-500">✕</button>
                                                        </div>
                                                    </div>
                                                );
                                            })}
                                            {rankKeywords.length === 0 && <p className="text-sm text-slate-400 text-center py-4">No keywords yet. Add one above.</p>}
                                        </div>

                                        {rankMsg && <div className={`text-xs px-2 py-1.5 rounded ${rankMsg.type === 'success' ? 'bg-green-50 text-green-700' : rankMsg.type === 'error' ? 'bg-red-50 text-red-700' : 'bg-blue-50 text-blue-700'}`}>{rankMsg.text}</div>}

                                        <div className="flex gap-2 pt-1">
                                            <button type="button" onClick={() => checkRankNow()} disabled={rankChecking || rankKeywords.length === 0 || !serpApiKey} className="flex-1 px-3 py-2 bg-emerald-600 text-white text-xs font-semibold rounded hover:bg-emerald-700 disabled:opacity-50">
                                                {rankChecking ? 'Checking…' : '⚡ Check Top 5 Now'}
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                {/* History panel — right 3/5 */}
                                <div className="col-span-3">
                                    <div className="bg-white border border-slate-200 rounded-lg overflow-hidden">
                                        <div className="px-5 py-3 border-b border-slate-200 flex items-center justify-between">
                                            <span className="text-sm font-semibold text-slate-700">Position History</span>
                                            <div className="flex gap-1.5">
                                                {[7, 30, 90].map(d => (
                                                    <button type="button" key={d} onClick={() => setRankDays(d)} className={`px-2 py-0.5 text-xs rounded ${rankDays === d ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'}`}>{d}d</button>
                                                ))}
                                            </div>
                                        </div>
                                        {rankLoading ? (
                                            <div className="flex items-center gap-3 p-8"><Spinner /><span className="text-slate-500">Loading history…</span></div>
                                        ) : Object.keys(rankHistory).length > 0 ? (
                                            <div className="divide-y divide-slate-100">
                                                {Object.entries(rankHistory).map(([kw, history]) => {
                                                    const latest = history[0];
                                                    const best   = Math.min(...history.map(h => h.position).filter(Boolean));
                                                    return (
                                                        <div key={kw} className="px-5 py-4">
                                                            <div className="flex items-center justify-between mb-2">
                                                                <span className="text-sm font-semibold text-slate-800">{kw}</span>
                                                                <div className="flex gap-4 text-xs text-slate-500">
                                                                    <span>Current: <strong className="text-slate-800">#{latest?.position ?? '—'}</strong></span>
                                                                    <span>Best: <strong className="text-slate-800">#{best || '—'}</strong></span>
                                                                    <span>{history.length} data points</span>
                                                                </div>
                                                            </div>
                                                            {/* Mini sparkline using SVG */}
                                                            <svg viewBox={`0 0 200 40`} className="w-full h-10" preserveAspectRatio="none">
                                                                {(() => {
                                                                    const pts = history.slice(0, 30).reverse().map(h => h.position).filter(Boolean);
                                                                    if (pts.length < 2) return null;
                                                                    const maxPos = Math.max(...pts);
                                                                    const minPos = Math.min(...pts);
                                                                    const range  = maxPos - minPos || 1;
                                                                    const points = pts.map((p, i) => {
                                                                        const x = (i / (pts.length - 1)) * 200;
                                                                        const y = 38 - ((maxPos - p) / range) * 34; // Lower rank = higher y
                                                                        return `${x},${y}`;
                                                                    }).join(' ');
                                                                    return <polyline points={points} fill="none" stroke="#6366f1" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />;
                                                                })()}
                                                            </svg>
                                                            {latest?.url && <a href={latest.url} target="_blank" rel="noopener" className="text-xs text-indigo-500 hover:underline truncate block mt-1">{latest.url}</a>}
                                                        </div>
                                                    );
                                                })}
                                            </div>
                                        ) : (
                                            <div className="p-10 text-center text-slate-400 text-sm">
                                                No history yet. Add keywords and run a check to start tracking.
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                </main>
            </div>
        </div>
        </>
    );
};

const SchemaRuleForm = ({ postTypes, categories, onAddRule }) => {
    const [postType, setPostType] = useState('post');
    const [category, setCategory] = useState('all');
    const [schemaType, setSchemaType] = useState('article');
    const [articleType, setArticleType] = useState('BlogPosting');

    return (
        <div className="grid grid-cols-1 md:grid-cols-5 gap-4 items-end">
            <div>
                <label className="block text-xs font-semibold text-slate-600 mb-1">Post Type</label>
                <select 
                    value={postType} 
                    onChange={(e) => setPostType(e.target.value)} 
                    className="w-full p-2 border border-slate-300 rounded text-sm bg-white cursor-pointer"
                >
                    {postTypes.map(t => (
                        <option key={t.name} value={t.name}>{t.label}</option>
                    ))}
                </select>
            </div>
            <div>
                <label className="block text-xs font-semibold text-slate-600 mb-1">Category Rule</label>
                <select 
                    value={category} 
                    onChange={(e) => setCategory(e.target.value)} 
                    disabled={postType !== 'post'}
                    className="w-full p-2 border border-slate-300 rounded text-sm bg-white disabled:bg-slate-100 disabled:text-slate-400 cursor-pointer"
                >
                    <option value="all">All Categories</option>
                    {categories.map(c => (
                        <option key={c.id} value={c.id}>{c.name}</option>
                    ))}
                </select>
            </div>
            <div>
                <label className="block text-xs font-semibold text-slate-600 mb-1">Output Schema</label>
                <select 
                    value={schemaType} 
                    onChange={(e) => setSchemaType(e.target.value)} 
                    className="w-full p-2 border border-slate-300 rounded text-sm bg-white cursor-pointer"
                >
                    <option value="article">Article</option>
                    <option value="recipe">Recipe</option>
                    <option value="event">Event</option>
                    <option value="faq">FAQPage</option>
                    <option value="product">Product</option>
                    <option value="local">LocalBusiness</option>
                </select>
            </div>
            {schemaType === 'article' ? (
                <div>
                    <label className="block text-xs font-semibold text-slate-600 mb-1">Article Type</label>
                    <select 
                        value={articleType} 
                        onChange={(e) => setArticleType(e.target.value)} 
                        className="w-full p-2 border border-slate-300 rounded text-sm bg-white cursor-pointer"
                    >
                        <option value="BlogPosting">BlogPosting</option>
                        <option value="Article">Article</option>
                        <option value="NewsArticle">NewsArticle</option>
                    </select>
                </div>
            ) : (
                <div />
            )}
            <div>
                <button type="button" 
                    onClick={() => {
                        onAddRule({ 
                            post_type: postType, 
                            category: postType === 'post' ? category : 'all', 
                            schema_type: schemaType,
                            ...(schemaType === 'article' ? { article_type: articleType } : {})
                        });
                    }}
                    className="w-full p-2 bg-amber-500 hover:bg-amber-600 text-white rounded text-sm font-semibold transition-colors h-[38px] flex items-center justify-center cursor-pointer border border-transparent"
                >
                    Add Rule
                </button>
            </div>
        </div>
    );
};

const NavItem = ({ id, label, active, onClick, onKeyDown }) => (
    <button
        type="button"
        role="tab"
        id={`holvek-tab-${id}`}
        aria-selected={active}
        aria-controls="holvek-tabpanel"
        tabIndex={active ? 0 : -1}
        onClick={onClick}
        onKeyDown={onKeyDown}
        className={`holvek-tab text-left px-4 py-2 rounded font-medium transition-colors ${active ? 'bg-slate-100 text-brand-600' : 'text-slate-600 hover:bg-slate-50'}`}
    >
        {label}
    </button>
);

const StatCard = ({ title, value, type = 'default' }) => (
    <div className="p-4 border rounded-lg shadow-sm bg-white">
        <h3 className="text-sm font-medium text-slate-500 m-0">{title}</h3>
        <p className={`text-3xl font-bold mt-2 mb-0 ${type === 'success' ? 'text-green-600' : 'text-slate-900'}`}>{value}</p>
    </div>
);

export default App;
