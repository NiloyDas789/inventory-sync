import { useState, useEffect } from 'react';
import {
    Page,
    Card,
    Layout,
    Button,
    Modal,
    Banner,
    DescriptionList,
    BlockStack,
    InlineStack,
    Text,
    Spinner,
    EmptyState,
} from '@shopify/polaris';
import { Head } from '@inertiajs/react';
import api from '../lib/axios';
import type { GoogleSheetsConnection } from '../types';

export default function GoogleSheetsConnectionPage() {
    const [loading, setLoading] = useState(true);
    const [connection, setConnection] = useState<GoogleSheetsConnection | null>(null);
    const [disconnectModal, setDisconnectModal] = useState(false);
    const [previewModal, setPreviewModal] = useState(false);
    const [previewData, setPreviewData] = useState<any>(null);
    const [connecting, setConnecting] = useState(false);

    useEffect(() => {
        loadConnection();
    }, []);

    const loadConnection = async () => {
        try {
            setLoading(true);
            const response = await api.get('/google-sheets/status');
            if (response.data.success) {
                setConnection(response.data.data);
            }
        } catch (error) {
            console.error('Failed to load connection:', error);
        } finally {
            setLoading(false);
        }
    };

    const handleConnect = async () => {
        try {
            setConnecting(true);
            const response = await api.get('/google-sheets/connect');
            // Redirect to Google OAuth
            if (response.data.redirect_url) {
                window.location.href = response.data.redirect_url;
            }
        } catch (error) {
            console.error('Failed to initiate connection:', error);
        } finally {
            setConnecting(false);
        }
    };

    const handleDisconnect = async () => {
        try {
            await api.post('/google-sheets/disconnect');
            setConnection(null);
            setDisconnectModal(false);
        } catch (error) {
            console.error('Failed to disconnect:', error);
        }
    };

    const handlePreview = async () => {
        try {
            const response = await api.post('/google-sheets/test');
            if (response.data.success) {
                setPreviewData(response.data.data);
                setPreviewModal(true);
            }
        } catch (error) {
            console.error('Failed to preview sheet:', error);
        }
    };

    if (loading) {
        return (
            <Page title="Google Sheets Connection">
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
            <Head title="Google Sheets Connection" />
            <Page
                title="Google Sheets Connection"
                primaryAction={
                    connection
                        ? {
                              content: 'Disconnect',
                              destructive: true,
                              onAction: () => setDisconnectModal(true),
                          }
                        : {
                              content: 'Connect Google Sheets',
                              onAction: handleConnect,
                              loading: connecting,
                          }
                }
            >
                <Layout>
                    <Layout.Section>
                        {connection ? (
                            <Card>
                                <BlockStack gap="400">
                                    <Text variant="headingMd" as="h2">
                                        Connection Status
                                    </Text>
                                    <Banner tone="success" title="Connected">
                                        <p>Your Google Sheets account is connected and ready to sync.</p>
                                    </Banner>
                                    <DescriptionList
                                        items={[
                                            {
                                                term: 'Sheet ID',
                                                description: connection.sheet_id,
                                            },
                                            {
                                                term: 'Sheet URL',
                                                description: (
                                                    <a
                                                        href={connection.sheet_url}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                    >
                                                        {connection.sheet_url}
                                                    </a>
                                                ),
                                            },
                                            {
                                                term: 'Last Synced',
                                                description: connection.last_synced_at
                                                    ? new Date(connection.last_synced_at).toLocaleString()
                                                    : 'Never',
                                            },
                                        ]}
                                    />
                                    <InlineStack gap="200">
                                        <Button url={connection.sheet_url} external>
                                            Open Sheet
                                        </Button>
                                        <Button onClick={handlePreview}>Preview Sheet</Button>
                                        <Button onClick={loadConnection}>Refresh</Button>
                                    </InlineStack>
                                </BlockStack>
                            </Card>
                        ) : (
                            <Card>
                                <EmptyState
                                    heading="Connect your Google Sheets"
                                    action={{
                                        content: 'Connect Google Sheets',
                                        onAction: handleConnect,
                                        loading: connecting,
                                    }}
                                    image="https://cdn.shopify.com/s/files/1/0757/9955/files/empty-state.svg"
                                >
                                    <p>Connect your Google Sheets account to start syncing inventory data.</p>
                                </EmptyState>
                            </Card>
                        )}
                    </Layout.Section>

                    <Layout.Section>
                        <Card>
                            <BlockStack gap="400">
                                <Text variant="headingMd" as="h2">
                                    Troubleshooting
                                </Text>
                                <Banner tone="info" title="Common Issues">
                                    <BlockStack gap="200">
                                        <p>
                                            <strong>Connection failed?</strong> Make sure you grant all required
                                            permissions when connecting.
                                        </p>
                                        <p>
                                            <strong>Sheet not found?</strong> Verify the sheet ID is correct and the
                                            sheet is accessible.
                                        </p>
                                        <p>
                                            <strong>Permission denied?</strong> Ensure the Google account has edit
                                            access to the sheet.
                                        </p>
                                    </BlockStack>
                                </Banner>
                            </BlockStack>
                        </Card>
                    </Layout.Section>
                </Layout>

                <Modal
                    open={disconnectModal}
                    onClose={() => setDisconnectModal(false)}
                    title="Disconnect Google Sheets"
                    primaryAction={{
                        content: 'Disconnect',
                        destructive: true,
                        onAction: handleDisconnect,
                    }}
                    secondaryActions={[
                        {
                            content: 'Cancel',
                            onAction: () => setDisconnectModal(false),
                        },
                    ]}
                >
                    <Modal.Section>
                        <Text as="p">
                            Are you sure you want to disconnect your Google Sheets account? You will need to reconnect
                            to continue syncing.
                        </Text>
                    </Modal.Section>
                </Modal>

                <Modal
                    open={previewModal}
                    onClose={() => setPreviewModal(false)}
                    title="Sheet Preview"
                    primaryAction={{
                        content: 'Close',
                        onAction: () => setPreviewModal(false),
                    }}
                >
                    <Modal.Section>
                        {previewData && (
                            <BlockStack gap="400">
                                <Text as="p">
                                    <strong>Title:</strong> {previewData.title}
                                </Text>
                                <Text as="p">
                                    <strong>Sheets:</strong> {previewData.sheets?.join(', ')}
                                </Text>
                            </BlockStack>
                        )}
                    </Modal.Section>
                </Modal>
            </Page>
        </>
    );
}

