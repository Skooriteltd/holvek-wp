import { useState, useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Notice, Spinner, TextControl } from '@wordpress/components';

const SITE_TYPES = [
    { id: 'blog', label: __('Blog / Personal', 'holvek'), icon: '✍️', desc: __('Content-focused site with regular posts', 'holvek') },
    { id: 'news', label: __('News / Media', 'holvek'), icon: '📰', desc: __('High-frequency publishing with timely content', 'holvek') },
    { id: 'business', label: __('Business / Agency', 'holvek'), icon: '🏢', desc: __('Company website with pages and services', 'holvek') },
    { id: 'ecommerce', label: __('eCommerce', 'holvek'), icon: '🛒', desc: __('Online store with product pages', 'holvek') },
];

const SetupWizard = ({ onComplete }) => {
    const config = window.holvekAdminConfig || {};
    const [step, setStep] = useState(0); // 0 = loading, 1 = configure, 2 = done
    const [autoData, setAutoData] = useState(null);
    const [siteType, setSiteType] = useState('blog');
    const [social, setSocial] = useState({ facebook: '', twitter: '', instagram: '', linkedin: '', youtube: '' });
    const [migrationResults, setMigrationResults] = useState({});
    const [migrationPreview, setMigrationPreview] = useState({});
    const [isMigrating, setIsMigrating] = useState({});
    const [isSaving, setIsSaving] = useState(false);
    const [error, setError] = useState(null);

    // Auto-detect on mount
    useEffect(() => {
        fetch(`${config.apiUrl}/setup/auto-detect`, {
            headers: { 'X-WP-Nonce': config.nonce }
        })
        .then(r => r.json())
        .then(data => {
            setAutoData(data);
            setSiteType(data.site_type || 'blog');
            setSocial(data.social || social);
            setStep(1);
        })
        .catch(() => {
            setStep(1); // Continue even if detection fails
        });
    }, []);

    const runMigration = async (pluginId, confirm) => {
        setIsMigrating(prev => ({ ...prev, [pluginId]: true }));
        try {
            const response = await fetch(`${config.apiUrl}/migration/run`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
                body: JSON.stringify({ plugin_id: pluginId, confirm: !!confirm })
            });
            const result = await response.json();
            if (!confirm) {
                setMigrationPreview(prev => ({ ...prev, [pluginId]: result }));
            } else {
                setMigrationResults(prev => ({ ...prev, [pluginId]: result }));
            }
        } catch (e) {
            setMigrationResults(prev => ({ ...prev, [pluginId]: { success: false, message: __('Migration failed.', 'holvek') } }));
        } finally {
            setIsMigrating(prev => ({ ...prev, [pluginId]: false }));
        }
    };

    const finishSetup = async (skip) => {
        setIsSaving(true);
        try {
            await fetch(`${config.apiUrl}/setup/complete`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
                body: JSON.stringify(skip ? { skip: true } : { site_type: siteType, social })
            });
            if (skip) {
                onComplete();
                return;
            }
            setStep(2);
            setTimeout(() => onComplete(), 2000);
        } catch (e) {
            setError(__('Failed to save. Please try again.', 'holvek'));
        } finally {
            setIsSaving(false);
        }
    };

    // ── Loading State ──
    if (step === 0) {
        return (
            <div style={styles.container}>
                <div style={styles.card}>
                    <div style={{ textAlign: 'center', padding: '48px 0' }}>
                        <Spinner />
                        <p style={{ marginTop: '16px', color: '#64748b' }} role="status">{__('Analyzing your site...', 'holvek')}</p>
                    </div>
                </div>
            </div>
        );
    }

    // ── Complete State ──
    if (step === 2) {
        return (
            <div style={styles.container}>
                <div style={styles.card}>
                    <div style={{ textAlign: 'center', padding: '48px 0' }}>
                        <div style={{ fontSize: '48px', marginBottom: '16px' }} aria-hidden="true">🎉</div>
                        <h2 style={{ margin: '0 0 8px', fontSize: '22px', color: '#0f172a' }}>{__('You are all set.', 'holvek')}</h2>
                        <p style={{ margin: 0, color: '#64748b' }}>{__('Holvek is using the choices you saved.', 'holvek')}</p>
                    </div>
                </div>
            </div>
        );
    }

    // ── Main Wizard (Single Page) ──
    const detectedPlugins = autoData?.detected_plugins || [];
    const hasSocialData = Object.values(social).some(v => v);

    return (
        <div style={styles.container}>
            <div style={styles.card}>
                {/* Header */}
                <div style={{ textAlign: 'center', marginBottom: '32px' }}>
                    <div style={{ fontSize: '36px', marginBottom: '8px' }} aria-hidden="true">🚀</div>
                    <h2 style={{ margin: '0 0 4px', fontSize: '22px', color: '#0f172a' }}>{__('Welcome to Holvek', 'holvek')}</h2>
                    <p style={{ margin: 0, color: '#64748b', fontSize: '14px' }}>
                        {__('Everything is already working. Just confirm a few details.', 'holvek')}
                    </p>
                </div>

                {error && (
                    <Notice status="error" isDismissible={true} onRemove={() => setError(null)}>
                        {error}
                    </Notice>
                )}

                {/* Section 1: Site Type */}
                <div style={styles.section}>
                    <h3 style={styles.sectionTitle}>{__('What type of site is this?', 'holvek')}</h3>
                    <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '10px' }}>
                        {SITE_TYPES.map(type => (
                            <button
                                key={type.id}
                                onClick={() => setSiteType(type.id)}
                                style={{
                                    ...styles.typeButton,
                                    borderColor: siteType === type.id ? '#3b82f6' : '#e2e8f0',
                                    background: siteType === type.id ? '#eff6ff' : '#fff',
                                }}
                            >
                                <span style={{ fontSize: '20px' }} aria-hidden="true">{type.icon}</span>
                                <div>
                                    <div style={{ fontWeight: 600, fontSize: '13px', color: '#0f172a' }}>{type.label}</div>
                                    <div style={{ fontSize: '11px', color: '#64748b', marginTop: '2px' }}>{type.desc}</div>
                                </div>
                            </button>
                        ))}
                    </div>
                </div>

                {/* Section 2: Migration (only if plugins detected) */}
                {detectedPlugins.length > 0 && (
                    <div style={styles.section}>
                        <h3 style={styles.sectionTitle}>{__('Import existing SEO data', 'holvek')}</h3>
                        <p style={{ fontSize: '13px', color: '#64748b', margin: '0 0 12px' }}>
                            {__('We detected SEO data from other plugins. Preview the counts first. Existing Holvek fields are not overwritten. Deactivate the other plugin after import so both plugins do not print titles and schema.', 'holvek')}
                        </p>
                        {detectedPlugins.map(plugin => (
                            <div key={plugin.id} style={styles.migrationRow}>
                                <div>
                                    <strong style={{ fontSize: '14px', color: '#0f172a' }}>{plugin.name}</strong>
                                    <span style={{ fontSize: '12px', color: '#64748b', marginLeft: '8px' }}>
                                        {sprintf(
                                            /* translators: %d: number of posts with imported SEO data. */
                                            __('%d posts with data', 'holvek'),
                                            plugin.posts
                                        )}
                                    </span>
                                </div>
                                {migrationResults[plugin.id] ? (
                                    <span style={{
                                        fontSize: '12px', fontWeight: 500,
                                        color: migrationResults[plugin.id].success ? '#166534' : '#991b1b'
                                    }}>
                                        {migrationResults[plugin.id].message}
                                    </span>
                                ) : migrationPreview[plugin.id] ? (
                                    <div>
                                        <p style={{ margin: '0 0 8px', fontSize: '12px', color: '#0f172a' }}>{migrationPreview[plugin.id].message}</p>
                                        <Button
                                            isPrimary
                                            isSmall
                                            isBusy={isMigrating[plugin.id]}
                                            onClick={() => runMigration(plugin.id, true)}
                                        >
                                            {isMigrating[plugin.id] ? __('Importing...', 'holvek') : __('Confirm import', 'holvek')}
                                        </Button>
                                    </div>
                                ) : (
                                    <Button
                                        isSecondary
                                        isSmall
                                        isBusy={isMigrating[plugin.id]}
                                        onClick={() => runMigration(plugin.id, false)}
                                    >
                                        {isMigrating[plugin.id] ? __('Checking...', 'holvek') : __('Preview import', 'holvek')}
                                    </Button>
                                )}
                            </div>
                        ))}
                    </div>
                )}

                {/* Section 3: Social (collapsed by default, pre-filled) */}
                <details style={styles.section}>
                    <summary style={{ ...styles.sectionTitle, cursor: 'pointer', userSelect: 'none' }}>
                        {__('Social profiles', 'holvek')} {hasSocialData && <span style={{ fontSize: '12px', color: '#166534', fontWeight: 400 }}>{__('Auto-detected', 'holvek')}</span>}
                    </summary>
                    <div style={{ marginTop: '12px' }}>
                        <TextControl label={__('Facebook', 'holvek')} value={social.facebook} onChange={v => setSocial({ ...social, facebook: v })} placeholder="https://facebook.com/yourpage" />
                        <TextControl label={__('Twitter / X', 'holvek')} value={social.twitter} onChange={v => setSocial({ ...social, twitter: v })} placeholder="@yourhandle" />
                        <TextControl label={__('Instagram', 'holvek')} value={social.instagram} onChange={v => setSocial({ ...social, instagram: v })} placeholder="https://instagram.com/yourpage" />
                        <TextControl label={__('LinkedIn', 'holvek')} value={social.linkedin} onChange={v => setSocial({ ...social, linkedin: v })} placeholder="https://linkedin.com/company/yours" />
                        <TextControl label={__('YouTube', 'holvek')} value={social.youtube} onChange={v => setSocial({ ...social, youtube: v })} placeholder="https://youtube.com/@yourchannel" />
                    </div>
                </details>

                {/* Finish Button */}
                <div style={{ marginTop: '24px', textAlign: 'center' }}>
                    <Button isPrimary isBusy={isSaving} onClick={() => finishSetup(false)} style={{ padding: '8px 40px', fontSize: '14px' }}>
                        {isSaving ? __('Saving...', 'holvek') : __('Finish setup', 'holvek')}
                    </Button>
                    <Button isSecondary onClick={() => finishSetup(true)} style={{ marginLeft: '8px' }}>
                        {__('Skip setup', 'holvek')}
                    </Button>
                    <p style={{ fontSize: '12px', color: '#475569', marginTop: '8px' }}>
                        {__('You can change any of these later in Settings. Skip leaves the current defaults in place.', 'holvek')}
                    </p>
                </div>
            </div>
        </div>
    );
};

const styles = {
    container: {
        maxWidth: '640px',
        margin: '0 auto',
        padding: '20px 0',
    },
    card: {
        background: '#fff',
        border: '1px solid #e2e8f0',
        borderRadius: '12px',
        padding: '32px',
        boxShadow: '0 1px 3px rgba(0,0,0,0.06)',
    },
    section: {
        marginTop: '24px',
        paddingTop: '24px',
        borderTop: '1px solid #f1f5f9',
    },
    sectionTitle: {
        fontSize: '15px',
        fontWeight: 600,
        color: '#0f172a',
        margin: '0 0 12px',
    },
    typeButton: {
        display: 'flex',
        alignItems: 'center',
        gap: '10px',
        padding: '12px 14px',
        border: '2px solid',
        borderRadius: '8px',
        cursor: 'pointer',
        textAlign: 'left',
        transition: 'all 0.15s',
    },
    migrationRow: {
        display: 'flex',
        justifyContent: 'space-between',
        alignItems: 'center',
        padding: '10px 14px',
        background: '#f8fafc',
        borderRadius: '6px',
        marginBottom: '8px',
        border: '1px solid #e2e8f0',
    },
};

export default SetupWizard;
