import { LucideIcon } from 'lucide-react';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    url: string;
    icon?: LucideIcon | null;
    isActive?: boolean;
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    flash: {
        success?: string | null;
        error?: string | null;
        generatedContext?: string | null;
    };
    [key: string]: unknown;
}

export interface Tag {
    id: number;
    name: string;
    color: string;
}

export interface Stage {
    id: number;
    name: string;
    slug?: string;
    color: string;
    position?: number;
    is_won?: boolean;
    is_lost?: boolean;
    leads?: Lead[];
}

export type LeadChannel = 'whatsapp' | 'instagram' | 'meta_ads' | 'manual' | 'otro';

export interface Lead {
    id: number;
    user_id: number;
    stage_id: number | null;
    name: string;
    phone: string | null;
    email: string | null;
    channel: LeadChannel;
    source: string | null;
    service_interest: string | null;
    notes: string | null;
    value: string | null;
    position: number;
    last_contact_at: string | null;
    created_at: string;
    updated_at: string;
    stage?: Stage | null;
    tags?: Tag[];
}

export interface Service {
    id: number;
    user_id: number;
    name: string;
    slug: string | null;
    category: string | null;
    short_description: string | null;
    description: string | null;
    ai_context: string | null;
    price: string | null;
    duration_minutes: number | null;
    is_active: boolean;
    sort_order: number;
    created_at: string;
    updated_at: string;
}

export interface Campaign {
    id: number;
    user_id: number;
    service_id: number | null;
    name: string;
    meta_campaign_id: string | null;
    platform: 'meta' | 'facebook' | 'instagram';
    meta_status: string | null;
    offer: string | null;
    is_active: boolean;
    created_at: string;
    updated_at: string;
    service?: { id: number; name: string } | null;
    media?: CampaignMedia[];
}

export interface CampaignMedia {
    id: number;
    campaign_id: number;
    type: 'image' | 'video';
    path: string | null;
    url: string | null;
    resolved_url: string | null;
    caption: string | null;
    sort_order: number;
}

export type AppointmentStatus = 'scheduled' | 'confirmed' | 'completed' | 'cancelled' | 'no_show';

export interface Appointment {
    id: number;
    user_id: number;
    lead_id: number | null;
    service_id: number | null;
    patient_name: string;
    patient_phone: string | null;
    patient_email: string | null;
    starts_at: string;
    ends_at: string;
    status: AppointmentStatus;
    notes: string | null;
    google_event_id: string | null;
    google_synced_at: string | null;
    google_sync_error: string | null;
    created_at: string;
    updated_at: string;
    service?: { id: number; name: string } | null;
    lead?: { id: number; name: string } | null;
}

export interface ServiceMedia {
    id: number;
    service_id: number;
    type: 'image' | 'video';
    path: string | null;
    url: string | null;
    resolved_url: string | null;
    caption: string | null;
    sort_order: number;
}

export interface ChatMedia {
    type: 'image' | 'video';
    url: string | null;
    caption: string;
    service: string;
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown; // This allows for additional properties...
}
