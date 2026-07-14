import { motion, useInView } from 'motion/react';
import { useId, useRef } from 'react';
import { AnimatedNumber } from './animated-number';

interface RadialGaugeProps {
    value: number;
    size?: number;
    stroke?: number;
    label?: string;
    suffix?: string;
}

/**
 * Medidor circular con relleno animado y degradado.
 */
export function RadialGauge({ value, size = 140, stroke = 11, label, suffix = '%' }: RadialGaugeProps) {
    const ref = useRef<HTMLDivElement>(null);
    const inView = useInView(ref, { once: true, margin: '-40px' });
    const gid = useId().replace(/:/g, '');

    const r = (size - stroke) / 2;
    const circ = 2 * Math.PI * r;
    const pct = Math.min(100, Math.max(0, value));
    const offset = circ - (pct / 100) * circ;

    return (
        <div ref={ref} className="relative inline-flex items-center justify-center" style={{ width: size, height: size }}>
            <svg width={size} height={size} className="-rotate-90">
                <defs>
                    <linearGradient id={`gauge-${gid}`} x1="0" y1="0" x2="1" y2="1">
                        <stop offset="0%" stopColor="var(--aurora-2)" />
                        <stop offset="100%" stopColor="var(--aurora-3)" />
                    </linearGradient>
                </defs>
                <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke="var(--muted)" strokeWidth={stroke} />
                <motion.circle
                    cx={size / 2}
                    cy={size / 2}
                    r={r}
                    fill="none"
                    stroke={`url(#gauge-${gid})`}
                    strokeWidth={stroke}
                    strokeLinecap="round"
                    strokeDasharray={circ}
                    initial={{ strokeDashoffset: circ }}
                    animate={{ strokeDashoffset: inView ? offset : circ }}
                    transition={{ duration: 1.5, ease: [0.16, 1, 0.3, 1], delay: 0.2 }}
                />
            </svg>
            <div className="absolute inset-0 flex flex-col items-center justify-center">
                <span className="font-display text-3xl leading-none">
                    <AnimatedNumber value={pct} />
                    <span className="text-xl">{suffix}</span>
                </span>
                {label && <span className="text-muted-foreground mt-1 text-xs">{label}</span>}
            </div>
        </div>
    );
}
