import { motion } from 'motion/react';
import { useId } from 'react';

interface SparklineProps {
    data: number[];
    className?: string;
    /** Color de la línea; por defecto hereda currentColor. */
    stroke?: string;
    /** Muestra el degradado de relleno bajo la línea. */
    fill?: boolean;
    strokeWidth?: number;
}

/**
 * Mini gráfico de línea (SVG) que se dibuja con una animación de trazo.
 */
export function Sparkline({ data, className, stroke = 'currentColor', fill = true, strokeWidth = 2 }: SparklineProps) {
    const w = 100;
    const h = 36;
    const pad = 3;
    const max = Math.max(...data);
    const min = Math.min(...data);
    const range = max - min || 1;

    const points = data.map((d, i) => {
        const x = (i / (data.length - 1)) * w;
        const y = h - pad - ((d - min) / range) * (h - pad * 2);
        return [x, y] as const;
    });

    const line = points.map((p, i) => `${i === 0 ? 'M' : 'L'} ${p[0].toFixed(2)} ${p[1].toFixed(2)}`).join(' ');
    const area = `${line} L ${w} ${h} L 0 ${h} Z`;
    const gid = useId().replace(/:/g, '');

    return (
        <svg viewBox={`0 0 ${w} ${h}`} preserveAspectRatio="none" className={className} fill="none">
            <defs>
                <linearGradient id={`spark-${gid}`} x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor={stroke} stopOpacity="0.28" />
                    <stop offset="100%" stopColor={stroke} stopOpacity="0" />
                </linearGradient>
            </defs>
            {fill && (
                <motion.path
                    d={area}
                    fill={`url(#spark-${gid})`}
                    initial={{ opacity: 0 }}
                    animate={{ opacity: 1 }}
                    transition={{ duration: 0.8, delay: 0.5 }}
                />
            )}
            <motion.path
                d={line}
                stroke={stroke}
                strokeWidth={strokeWidth}
                strokeLinecap="round"
                strokeLinejoin="round"
                vectorEffect="non-scaling-stroke"
                initial={{ pathLength: 0 }}
                animate={{ pathLength: 1 }}
                transition={{ duration: 1.3, ease: [0.16, 1, 0.3, 1] }}
            />
        </svg>
    );
}
