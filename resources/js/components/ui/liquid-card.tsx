import { cn } from '@/lib/utils';
import { motion, useMotionValue, useSpring, useTransform, type HTMLMotionProps } from 'motion/react';
import { useRef } from 'react';

interface LiquidCardProps extends HTMLMotionProps<'div'> {
    /** Intensidad de la inclinación 3D en grados. 0 la desactiva. */
    tilt?: number;
}

/**
 * Tarjeta "Liquid Glass" con reflejo especular que sigue al cursor
 * y una sutil inclinación 3D en perspectiva. Acepta todas las props de motion.
 */
export function LiquidCard({ className, children, tilt = 7, style, onMouseMove, onMouseLeave, ...props }: LiquidCardProps) {
    const ref = useRef<HTMLDivElement>(null);

    // Posición normalizada del cursor (-0.5 … 0.5).
    const px = useMotionValue(0);
    const py = useMotionValue(0);

    const spring = { stiffness: 220, damping: 18, mass: 0.4 };
    const rotateX = useSpring(useTransform(py, [-0.5, 0.5], [tilt, -tilt]), spring);
    const rotateY = useSpring(useTransform(px, [-0.5, 0.5], [-tilt, tilt]), spring);

    const handleMove = (e: React.MouseEvent<HTMLDivElement>) => {
        const el = ref.current;
        if (el) {
            const r = el.getBoundingClientRect();
            const nx = (e.clientX - r.left) / r.width;
            const ny = (e.clientY - r.top) / r.height;
            px.set(nx - 0.5);
            py.set(ny - 0.5);
            el.style.setProperty('--mx', `${nx * 100}%`);
            el.style.setProperty('--my', `${ny * 100}%`);
        }
        onMouseMove?.(e);
    };

    const handleLeave = (e: React.MouseEvent<HTMLDivElement>) => {
        px.set(0);
        py.set(0);
        const el = ref.current;
        if (el) {
            el.style.setProperty('--mx', '50%');
            el.style.setProperty('--my', '0%');
        }
        onMouseLeave?.(e);
    };

    return (
        <motion.div
            ref={ref}
            onMouseMove={handleMove}
            onMouseLeave={handleLeave}
            style={{ rotateX, rotateY, transformPerspective: 900, transformStyle: 'preserve-3d', ...style }}
            className={cn('liquid-glass', className)}
            {...props}
        >
            {children}
        </motion.div>
    );
}
