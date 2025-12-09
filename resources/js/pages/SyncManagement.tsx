import { useState, useEffect } from 'react';
import {
    Page,
    Card,
    Layout,
    FormLayout,
    RadioButton,
    Button,
    BlockStack,
    InlineStack,
    Text,
    Banner,
    Spinner,
    ProgressBar,
    Scrollable,
    Select,
    Checkbox,
} from '@shopify/polaris';
import { Head } from '@inertiajs/react';
import { AppLayout } from '../layouts/AppLayout';
import api from '../lib/axios';
import type { SyncStartRequest, SyncProgress, SyncLog } from '../types';

/**
 * Sync Management Page Component
 * 
 * Manages synchronization operations between Shopify and Google Sheets.
 * Wrapped in AppLayout to provide Polaris context and navigation.
 */
export default function SyncManagementPage() {
    const [loading, setLoading] = useState(false);
    const [syncType, setSyncType] = useState<'full' | 'incremental' | 'selective'>('full');
    const [conflictResolution, setConflictResolution] = useState<string>('shopify_wins');
    const [asyncMode, setAsyncMode] = useState(true);
    const [dryRun, setDryRun] = useState(false);
    const [previewOnly, setPreviewOnly] = useState(false);
    const [currentSync, setCurrentSync] = useState<SyncLog | null>(null);
    const [progress, setProgress] = useState<SyncProgress | null>(null);
    const [logs, setLogs] = useState<string[]>([]);
    const [syncing, setSyncing] = useState(false);

    useEffect(() => {
        if (currentSync) {
            const interval = setInterval(() => {
                loadProgress();
            }, 2000);
            return () => clearInterval(interval);
        }
    }, [currentSync]);

    const loadProgress = async () => {
        if (!currentSync) return;

        try {
            const response = await api.get(`/sync/progress/${currentSync.id}`);
            if (response.data.success) {
                setProgress(response.data.data);
            }
        } catch (error) {
            console.error('Failed to load progress:', error);
        }
    };

    const handleStartSync = async () => {
        try {
            setSyncing(true);
            const request: SyncStartRequest = {
                strategy: syncType,
                async: asyncMode,
                conflict_resolution: conflictResolution as any,
                options: {
                    dry_run: dryRun,
                    preview_only: previewOnly,
                },
            };

            const response = await api.post('/sync/start', request);
            if (response.data.success) {
                setCurrentSync(response.data.data);
                setLogs([`Sync started: ${response.data.data.sync_log_id}`]);
            }
        } catch (error) {
            console.error('Failed to start sync:', error);
        } finally {
            setSyncing(false);
        }
    };

    return (
        <AppLayout>
            <Head title="Sync Management" />
            <Page
                title="Sync Management"
                primaryAction={{
                    content: 'Start Sync',
                    onAction: handleStartSync,
                    loading: syncing,
                }}
            >
                <Layout>
                    <Layout.Section>
                        <Card>
                            <BlockStack gap="400">
                                <Text variant="headingMd" as="h2">
                                    Sync Configuration
                                </Text>
                                <FormLayout>
                                    <FormLayout.Group>
                                        <Text variant="headingSm" as="h3">
                                            Sync Type
                                        </Text>
                                        <BlockStack gap="200">
                                            <RadioButton
                                                label="Full Sync"
                                                checked={syncType === 'full'}
                                                id="full"
                                                name="syncType"
                                                onChange={() => setSyncType('full')}
                                                helpText="Sync all products and inventory bidirectionally"
                                            />
                                            <RadioButton
                                                label="Incremental Sync"
                                                checked={syncType === 'incremental'}
                                                id="incremental"
                                                name="syncType"
                                                onChange={() => setSyncType('incremental')}
                                                helpText="Only sync items changed since last sync"
                                            />
                                            <RadioButton
                                                label="Selective Sync"
                                                checked={syncType === 'selective'}
                                                id="selective"
                                                name="syncType"
                                                onChange={() => setSyncType('selective')}
                                                helpText="Sync specific products or variants"
                                            />
                                        </BlockStack>
                                    </FormLayout.Group>

                                    <FormLayout.Group>
                                        <Select
                                            label="Conflict Resolution"
                                            options={[
                                                { label: 'Shopify Wins', value: 'shopify_wins' },
                                                { label: 'Sheets Wins', value: 'sheets_wins' },
                                                { label: 'Manual Review', value: 'manual' },
                                                { label: 'Merge', value: 'merge' },
                                            ]}
                                            value={conflictResolution}
                                            onChange={setConflictResolution}
                                        />
                                    </FormLayout.Group>

                                    <FormLayout.Group>
                                        <Checkbox
                                            label="Async Mode"
                                            checked={asyncMode}
                                            onChange={setAsyncMode}
                                            helpText="Process sync in background (recommended for large datasets)"
                                        />
                                        <Checkbox
                                            label="Dry Run"
                                            checked={dryRun}
                                            onChange={setDryRun}
                                            helpText="Validate without applying changes"
                                        />
                                        <Checkbox
                                            label="Preview Only"
                                            checked={previewOnly}
                                            onChange={setPreviewOnly}
                                            helpText="Generate preview without syncing"
                                        />
                                    </FormLayout.Group>
                                </FormLayout>
                            </BlockStack>
                        </Card>
                    </Layout.Section>

                    {currentSync && (
                        <Layout.Section>
                            <Card>
                                <BlockStack gap="400">
                                    <Text variant="headingMd" as="h2">
                                        Sync Progress
                                    </Text>
                                    {progress && (
                                        <>
                                            <ProgressBar progress={progress.percentage} size="small" />
                                            <InlineStack align="space-between" gap="200">
                                                <Text as="p">
                                                    Status: <strong>{progress.status}</strong>
                                                </Text>
                                                <Text as="p">
                                                    Processed: <strong>{progress.records_processed}</strong>
                                                    {progress.total_records && ` / ${progress.total_records}`}
                                                </Text>
                                                <Text as="p">
                                                    Progress: <strong>{progress.percentage}%</strong>
                                                </Text>
                                            </InlineStack>
                                        </>
                                    )}
                                    {currentSync.status === 'processing' && <Spinner size="small" />}
                                </BlockStack>
                            </Card>
                        </Layout.Section>
                    )}

                    <Layout.Section>
                        <Card>
                            <BlockStack gap="400">
                                <Text variant="headingMd" as="h2">
                                    Sync Logs
                                </Text>
                                <Scrollable style={{ height: '300px' }}>
                                    {logs.length > 0 ? (
                                        <BlockStack gap="200">
                                            {logs.map((log, index) => (
                                                <Text key={index} as="p">
                                                    {log}
                                                </Text>
                                            ))}
                                        </BlockStack>
                                    ) : (
                                        <Text as="p" tone="subdued">
                                            No logs yet. Start a sync to see progress here.
                                        </Text>
                                    )}
                                </Scrollable>
                            </BlockStack>
                        </Card>
                    </Layout.Section>
                </Layout>
            </Page>
        </AppLayout>
    );
}

