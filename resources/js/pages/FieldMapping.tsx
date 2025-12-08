import { useState, useEffect } from 'react';
import {
    Page,
    Card,
    Layout,
    FormLayout,
    Checkbox,
    TextField,
    Button,
    BlockStack,
    InlineStack,
    Text,
    Banner,
    Spinner,
    Select,
    EmptyState,
} from '@shopify/polaris';
import { Head } from '@inertiajs/react';
import api from '../lib/axios';
import type { SyncFieldMapping } from '../types';

const PRESET_CONFIGS = [
    { label: 'Basic Inventory', value: 'basic' },
    { label: 'Full Product Details', value: 'full' },
    { label: 'Price & Inventory Only', value: 'price_inventory' },
];

const AVAILABLE_FIELDS = [
    { value: 'product_title', label: 'Product Title' },
    { value: 'variant_title', label: 'Variant Title' },
    { value: 'variant_sku', label: 'SKU' },
    { value: 'variant_price', label: 'Price' },
    { value: 'variant_inventory_quantity', label: 'Inventory Quantity' },
    { value: 'variant_cost', label: 'Cost' },
    { value: 'product_handle', label: 'Product Handle' },
    { value: 'variant_weight', label: 'Weight' },
];

const COLUMN_LETTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('');

export default function FieldMappingPage() {
    const [loading, setLoading] = useState(true);
    const [mappings, setMappings] = useState<SyncFieldMapping[]>([]);
    const [saving, setSaving] = useState(false);
    const [selectedPreset, setSelectedPreset] = useState<string>('');

    useEffect(() => {
        loadMappings();
    }, []);

    const loadMappings = async () => {
        try {
            setLoading(true);
            // This endpoint would need to be created in the backend
            // For now, we'll use a placeholder
            const response = await api.get('/sync/field-mappings');
            if (response.data.success) {
                setMappings(response.data.data || []);
            }
        } catch (error) {
            console.error('Failed to load mappings:', error);
        } finally {
            setLoading(false);
        }
    };

    const handlePresetChange = (preset: string) => {
        setSelectedPreset(preset);
        // Apply preset configuration
        const presetMappings: Record<string, Partial<SyncFieldMapping>[]> = {
            basic: [
                { shopify_field: 'variant_sku', sheet_column: 'A', is_active: true, display_order: 1 },
                { shopify_field: 'variant_inventory_quantity', sheet_column: 'B', is_active: true, display_order: 2 },
            ],
            full: AVAILABLE_FIELDS.slice(0, 8).map((field, index) => ({
                shopify_field: field.value,
                sheet_column: COLUMN_LETTERS[index],
                is_active: true,
                display_order: index + 1,
            })),
            price_inventory: [
                { shopify_field: 'variant_sku', sheet_column: 'A', is_active: true, display_order: 1 },
                { shopify_field: 'variant_price', sheet_column: 'B', is_active: true, display_order: 2 },
                { shopify_field: 'variant_inventory_quantity', sheet_column: 'C', is_active: true, display_order: 3 },
            ],
        };

        if (presetMappings[preset]) {
            setMappings(presetMappings[preset] as SyncFieldMapping[]);
        }
    };

    const handleFieldToggle = (field: string, checked: boolean) => {
        setMappings((prev) =>
            prev.map((m) => (m.shopify_field === field ? { ...m, is_active: checked } : m))
        );
    };

    const handleColumnChange = (field: string, column: string) => {
        setMappings((prev) =>
            prev.map((m) => (m.shopify_field === field ? { ...m, sheet_column: column } : m))
        );
    };

    const handleSave = async () => {
        try {
            setSaving(true);
            await api.post('/sync/field-mappings', { mappings });
            // Show success message
        } catch (error) {
            console.error('Failed to save mappings:', error);
        } finally {
            setSaving(false);
        }
    };

    if (loading) {
        return (
            <Page title="Field Mapping">
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
            <Head title="Field Mapping" />
            <Page
                title="Field Mapping Configuration"
                primaryAction={{
                    content: 'Save Mappings',
                    onAction: handleSave,
                    loading: saving,
                }}
            >
                <Layout>
                    <Layout.Section>
                        <Card>
                            <BlockStack gap="400">
                                <Text variant="headingMd" as="h2">
                                    Preset Configurations
                                </Text>
                                <Select
                                    label="Select a preset"
                                    options={[{ label: 'Choose a preset...', value: '' }, ...PRESET_CONFIGS]}
                                    value={selectedPreset}
                                    onChange={handlePresetChange}
                                />
                                <Banner tone="info">
                                    <p>
                                        Presets provide quick configurations for common use cases. You can customize
                                        fields after selecting a preset.
                                    </p>
                                </Banner>
                            </BlockStack>
                        </Card>
                    </Layout.Section>

                    <Layout.Section>
                        <Card>
                            <BlockStack gap="400">
                                <Text variant="headingMd" as="h2">
                                    Field Mappings
                                </Text>
                                {mappings.length > 0 ? (
                                    <FormLayout>
                                        {mappings.map((mapping) => {
                                            const field = AVAILABLE_FIELDS.find((f) => f.value === mapping.shopify_field);
                                            return (
                                                <FormLayout.Group key={mapping.shopify_field}>
                                                    <Checkbox
                                                        label={field?.label || mapping.shopify_field}
                                                        checked={mapping.is_active}
                                                        onChange={(checked) =>
                                                            handleFieldToggle(mapping.shopify_field, checked)
                                                        }
                                                    />
                                                    <Select
                                                        label="Sheet Column"
                                                        options={COLUMN_LETTERS.map((letter) => ({
                                                            label: letter,
                                                            value: letter,
                                                        }))}
                                                        value={mapping.sheet_column}
                                                        onChange={(value) =>
                                                            handleColumnChange(mapping.shopify_field, value)
                                                        }
                                                        disabled={!mapping.is_active}
                                                    />
                                                </FormLayout.Group>
                                            );
                                        })}
                                    </FormLayout>
                                ) : (
                                    <EmptyState
                                        heading="No field mappings"
                                        image="https://cdn.shopify.com/s/files/1/0757/9955/files/empty-state.svg"
                                        action={{
                                            content: 'Select a preset',
                                            onAction: () => {},
                                        }}
                                    >
                                        <p>Select a preset configuration or create custom mappings.</p>
                                    </EmptyState>
                                )}
                            </BlockStack>
                        </Card>
                    </Layout.Section>
                </Layout>
            </Page>
        </>
    );
}

