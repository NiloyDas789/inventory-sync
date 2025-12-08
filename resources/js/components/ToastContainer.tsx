import { useState, useCallback, useEffect } from 'react';
import { Toast } from '@shopify/polaris';

interface ToastMessage {
    id: string;
    content: string;
    error?: boolean;
}

let toastListeners: Array<(toast: ToastMessage | null) => void> = [];

export function showToast(content: string, error = false) {
    const toast: ToastMessage = {
        id: Date.now().toString(),
        content,
        error,
    };
    toastListeners.forEach((listener) => listener(toast));
}

export function useToast() {
    const [toast, setToast] = useState<ToastMessage | null>(null);

    useEffect(() => {
        toastListeners.push(setToast);
        return () => {
            toastListeners = toastListeners.filter((listener) => listener !== setToast);
        };
    }, []);

    const handleDismiss = useCallback(() => {
        setToast(null);
    }, []);

    return {
        toast: toast ? (
            <Toast
                content={toast.content}
                error={toast.error}
                onDismiss={handleDismiss}
                duration={toast.error ? 10000 : 5000}
            />
        ) : null,
    };
}

