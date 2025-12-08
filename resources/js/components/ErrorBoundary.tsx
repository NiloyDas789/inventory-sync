import { Banner, BlockStack, Button, Card, Page } from '@shopify/polaris';
import { Component, ReactNode } from 'react';

interface Props {
    children: ReactNode;
}

interface State {
    hasError: boolean;
    error: Error | null;
}

export class ErrorBoundary extends Component<Props, State> {
    constructor(props: Props) {
        super(props);
        this.state = { hasError: false, error: null };
    }

    static getDerivedStateFromError(error: Error): State {
        return { hasError: true, error };
    }

    componentDidCatch(error: Error, errorInfo: { componentStack: string }) {
        console.error('Error caught by boundary:', error, errorInfo);
    }

    render() {
        if (this.state.hasError) {
            return (
                <Page title="Error">
                    <Card>
                        <BlockStack gap="400">
                            <Banner tone="critical" title="Something went wrong">
                                <p>{this.state.error?.message || 'An unexpected error occurred'}</p>
                            </Banner>
                            <Button onClick={() => window.location.reload()}>Reload Page</Button>
                        </BlockStack>
                    </Card>
                </Page>
            );
        }

        return this.props.children;
    }
}
