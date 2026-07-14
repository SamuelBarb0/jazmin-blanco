import { animate, useInView } from 'motion/react';
import { useEffect, useRef, useState } from 'react';

interface AnimatedNumberProps {
    value: number;
    duration?: number;
    format?: (n: number) => string;
    className?: string;
}

/**
 * Cuenta progresiva (count-up) que arranca cuando el elemento entra en pantalla.
 */
export function AnimatedNumber({ value, duration = 1.4, format, className }: AnimatedNumberProps) {
    const ref = useRef<HTMLSpanElement>(null);
    const inView = useInView(ref, { once: true, margin: '-40px' });
    const [display, setDisplay] = useState(0);

    useEffect(() => {
        if (!inView) return;
        const controls = animate(0, value, {
            duration,
            ease: [0.16, 1, 0.3, 1],
            onUpdate: (latest) => setDisplay(latest),
        });
        return () => controls.stop();
    }, [inView, value, duration]);

    return (
        <span ref={ref} className={className}>
            {format ? format(display) : Math.round(display).toLocaleString('es-CO')}
        </span>
    );
}
