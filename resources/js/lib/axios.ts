import axios, { AxiosInstance, AxiosError, InternalAxiosRequestConfig } from 'axios';
import { getSessionToken } from './app-bridge';

// Create axios instance
const api: AxiosInstance = axios.create({
    baseURL: '/',
    headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
    },
});

// Request interceptor to attach session token
api.interceptors.request.use(
    async (config: InternalAxiosRequestConfig) => {
        try {
            const token = await getSessionToken();
            if (token && config.headers) {
                config.headers['Authorization'] = `Bearer ${token}`;
            }
        } catch (error) {
            console.error('Failed to attach session token:', error);
            // Continue without token - backend will handle auth
        }
        return config;
    },
    (error: AxiosError) => {
        return Promise.reject(error);
    }
);

// Response interceptor for error handling
api.interceptors.response.use(
    (response) => response,
    async (error: AxiosError) => {
        if (error.response?.status === 401) {
            // Handle authentication failure
            console.error('Authentication failed');
            // App Bridge will handle redirect
        }
        
        if (error.response?.status === 403) {
            console.error('Access forbidden');
        }
        
        return Promise.reject(error);
    }
);

export default api;

