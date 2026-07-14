import { type LeadChannel } from '@/types';
import { CircleDot, Instagram, Megaphone, MessageCircle, User, type LucideIcon } from 'lucide-react';

interface ColorSet {
    dot: string;
    soft: string;
    text: string;
    border: string;
    bar: string;
}

/** Paleta por token de color usada en etapas y etiquetas. */
export const palette: Record<string, ColorSet> = {
    sky: { dot: 'bg-sky-500', soft: 'bg-sky-500/10', text: 'text-sky-600 dark:text-sky-400', border: 'border-sky-500/30', bar: 'bg-sky-500' },
    blue: { dot: 'bg-blue-500', soft: 'bg-blue-500/10', text: 'text-blue-600 dark:text-blue-400', border: 'border-blue-500/30', bar: 'bg-blue-500' },
    cyan: { dot: 'bg-cyan-500', soft: 'bg-cyan-500/10', text: 'text-cyan-600 dark:text-cyan-400', border: 'border-cyan-500/30', bar: 'bg-cyan-500' },
    violet: { dot: 'bg-violet-500', soft: 'bg-violet-500/10', text: 'text-violet-600 dark:text-violet-400', border: 'border-violet-500/30', bar: 'bg-violet-500' },
    amber: { dot: 'bg-amber-500', soft: 'bg-amber-500/10', text: 'text-amber-600 dark:text-amber-400', border: 'border-amber-500/30', bar: 'bg-amber-500' },
    emerald: { dot: 'bg-emerald-500', soft: 'bg-emerald-500/10', text: 'text-emerald-600 dark:text-emerald-400', border: 'border-emerald-500/30', bar: 'bg-emerald-500' },
    rose: { dot: 'bg-rose-500', soft: 'bg-rose-500/10', text: 'text-rose-600 dark:text-rose-400', border: 'border-rose-500/30', bar: 'bg-rose-500' },
    slate: { dot: 'bg-slate-400', soft: 'bg-slate-500/10', text: 'text-slate-600 dark:text-slate-300', border: 'border-slate-500/30', bar: 'bg-slate-400' },
};

export const colorOf = (token: string): ColorSet => palette[token] ?? palette.slate;

export const channelMeta: Record<LeadChannel, { label: string; icon: LucideIcon; className: string }> = {
    whatsapp: { label: 'WhatsApp', icon: MessageCircle, className: 'text-emerald-500' },
    instagram: { label: 'Instagram', icon: Instagram, className: 'text-pink-500' },
    meta_ads: { label: 'Meta Ads', icon: Megaphone, className: 'text-sky-500' },
    manual: { label: 'Manual', icon: User, className: 'text-slate-500' },
    otro: { label: 'Otro', icon: CircleDot, className: 'text-slate-400' },
};

export const cop = (value: string | number | null): string | null => {
    if (value === null || value === '') return null;
    const n = Number(value);
    if (Number.isNaN(n)) return null;
    return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n);
};
