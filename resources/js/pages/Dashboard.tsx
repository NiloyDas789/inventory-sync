import { useState, useEffect } from 'react';
import {
    Page,
    Card,
    Layout,
    Badge,
    Button,
    DataTable,
    Banner,
    Spinner,
    BlockStack,
    InlineStack,
    Text,
    EmptyState,
} from '@shopify/polaris';
import { Head } from '@inertiajs/react';
import api from '../lib/axios';
import type { GoogleSheetsConnection, SyncLog } from '../types';

export default function Dashboard() {
    const [loading, setLoading] = useState(true);
    const [connection, setConnection] = useState<GoogleSheetsConnection | null>(null);
    const [syncLogs, setSyncLogs] = useState<SyncLog[]>([]);
    const [syncing, setSyncing] = useState(false);

    useEffect(() => {
        loadData();
    }, []);

    const loadData = async () => {
        try {
            setLoading(true);
            const [connectionRes, logsRes] = await Promise.all([
                api.get('/google-sheets/status'),
                api.get('/sync/status?limit=10'),
            ]);

            if (connectionRes.data.success) {
                setConnection(connectionRes.data.data);
            }

            if (logsRes.data.success) {
                setSyncLogs(logsRes.data.data);
            }
        } catch (error) {
            console.error('Failed to load dashboard data:', error);
        } finally {
            setLoading(false);
        }
    };

    const handleSync = async (type: 'products' | 'inventory' | 'full') => {
        try {
            setSyncing(true);
            const endpoint = type === 'full' ? '/sync/full' : `/sync/${type}`;
            await api.post(endpoint);
            
            // Show success message
            // Reload data
            await loadData();
        } catch (error) {
            console.error('Sync failed:', error);
        } finally {
            setSyncing(false);
        }
    };

    const getStatusBadge = (status: string) => {
        const statusMap: Record<string, { status: 'success' | 'info' | 'warning' | 'critical'; label: string }> = {
            completed: { status: 'success', label: 'Completed' },
            processing: { status: 'info', label: 'Processing' },
            pending: { status: 'info', label: 'Pending' },
            failed: { status: 'critical', label: 'Failed' },
        };

        const badge = statusMap[status] || { status: 'info' as const, label: status };
        return <Badge tone={badge.status}>{badge.label}</Badge>;
    };

    const formatDate = (dateString: string | null) => {
        if (!dateString) return 'Never';
        return new Date(dateString).toLocaleString();
    };

    const syncTableRows = syncLogs.map((log) => {
        const statusMap: Record<string, string> = {
            completed: 'Completed',
            processing: 'Processing',
            pending: 'Pending',
            failed: 'Failed',
        };
        return [
            log.id.toString(),
            log.sync_type,
            statusMap[log.status] || log.status,
            log.records_processed.toString(),
            formatDate(log.completed_at || log.started_at),
        ];
    });

    if (loading) {
        return (
            <Page title="Dashboard">
                <Layout>
                    <Layout.Section>
                        <Card>
                            <InlineStack align="center">
                                <Spinner size="large" />
                            </InlineStack>
                        </Card>
                    </Layout.Section>
                </Layout>
            </Page>
        );
    }

    return (
        <>
            <Head title="Dashboard" />
            <Page
                title="Inventory Sync Dashboard"
                primaryAction={{
                    content: 'Start Full Sync',
                    onAction: () => handleSync('full'),
                    loading: syncing,
                }}
            >
                <Layout>
                    <Layout.Section>
                        <Card>
                            <BlockStack gap="400">
                                <Text variant="headingMd" as="h2">
                                    Google Sheets Connection
                                </Text>
                                {connection ? (
                                    <BlockStack gap="200">
                                        <Banner tone="success" title="Connected">
                                            <p>
                                                Sheet ID: <strong>{connection.sheet_id}</strong>
                                            </p>
                                            <p>
                                                Last synced: <strong>{formatDate(connection.last_synced_at)}</strong>
                                            </p>
                                        </Banner>
                                        <InlineStack gap="200">
                                            <Button
                                                url={connection.sheet_url}
                                                external
                                            >
                                                Open Sheet
                                            </Button>
                                        </InlineStack>
                                    </BlockStack>
                                ) : (
                                    <Banner tone="warning" title="Not Connected">
                                        <p>Please connect your Google Sheets account to start syncing.</p>
                                    </Banner>
                                )}
                            </BlockStack>
                        </Card>
                    </Layout.Section>

                    <Layout.Section>
                        <Card>
                            <BlockStack gap="400">
                                <InlineStack align="space-between" blockAlign="center">
                                    <Text variant="headingMd" as="h2">
                                        Sync History
                                    </Text>
                                    <Button onClick={loadData}>Refresh</Button>
                                </InlineStack>
                                {syncLogs.length > 0 ? (
                                    <DataTable
                                        columnContentTypes={['text', 'text', 'text', 'numeric', 'text']}
                                        headings={['ID', 'Type', 'Status', 'Records', 'Date']}
                                        rows={syncTableRows}
                                    />
                                ) : (
                                    <EmptyState
                                        heading="No sync history"
                                        image="https://cdn.shopify.com/s/files/1/0757/9955/files/empty-state.svg"
                                        action={{
                                            content: 'Start your first sync',
                                            onAction: () => handleSync('full'),
                                        }}
                                    >
                                        <p>Start syncing your inventory to see history here.</p>
                                    </EmptyState>
                                )}
                            </BlockStack>
                        </Card>
                    </Layout.Section>

                    <Layout.Section>
                        <Card>
                            <BlockStack gap="400">
                                <Text variant="headingMd" as="h2">
                                    Quick Actions
                                </Text>
                                <InlineStack align="space-between" gap="200">
                                    <Button
                                        onClick={() => handleSync('products')}
                                        loading={syncing}
                                    >
                                        Sync Products
                                    </Button>
                                    <Button
                                        onClick={() => handleSync('inventory')}
                                        loading={syncing}
                                    >
                                        Sync Inventory
                                    </Button>
                                    <Button
                                        onClick={() => handleSync('full')}
                                        loading={syncing}
                                        variant="primary"
                                    >
                                        Full Sync
                                    </Button>
                                </InlineStack>
                            </BlockStack>
                        </Card>
                    </Layout.Section>
                </Layout>
            </Page>
        </>
    );
}

