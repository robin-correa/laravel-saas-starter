import type { Auth } from '@/types/auth';
import type { Tenancy } from '@/types/tenancy';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            tenancy: Tenancy;
            [key: string]: unknown;
        };
    }
}
