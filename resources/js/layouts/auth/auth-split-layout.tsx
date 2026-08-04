import AppLogoIcon from '@/components/app-logo-icon';
import { Link } from '@inertiajs/react';

interface AuthLayoutProps {
    children: React.ReactNode;
    title?: string;
    description?: string;
}

const highlights = [
    'Bot Claude que agenda valoraciones 24/7',
    'WhatsApp + Instagram en un solo panel',
    'Métricas en vivo y pipeline de pacientes',
];

export default function AuthSplitLayout({ children, title, description }: AuthLayoutProps) {
    return (
        <div className="relative grid min-h-dvh lg:grid-cols-2">
            {/* Panel de marca */}
            <div className="bg-sidebar text-sidebar-foreground relative hidden flex-col justify-between overflow-hidden p-10 lg:flex">
                <div className="aurora-mesh animate-float-slow absolute inset-0 opacity-90" />
                <div className="grain absolute inset-0" />

                <Link href={route('home')} className="relative z-10 flex items-center gap-2 text-white">
                    <div className="flex size-9 items-center justify-center rounded-lg bg-white ring-1 ring-white/20">
                        <AppLogoIcon className="size-8" />
                    </div>
                    <span className="font-medium">Dra. Jasmin Blanco</span>
                </Link>

                <div className="relative z-10 max-w-md">
                    <h2 className="font-display text-4xl leading-tight text-white">
                        El consultorio que <span className="text-sky-300 italic">conversa</span>, agenda y mide solo.
                    </h2>
                    <ul className="mt-8 space-y-3">
                        {highlights.map((h) => (
                            <li key={h} className="flex items-center gap-3 text-sm text-white/80">
                                <span className="bg-sky-400/20 text-sky-300 flex size-6 shrink-0 items-center justify-center rounded-full text-xs">✦</span>
                                {h}
                            </li>
                        ))}
                    </ul>
                </div>

                <p className="relative z-10 text-xs text-white/50">Consultorio Estético · Medicina estética premium</p>
            </div>

            {/* Formulario */}
            <div className="flex items-center justify-center p-6 sm:p-10">
                <div className="w-full max-w-sm">
                    {/* En móvil no hay panel de marca, así que aquí va el logo
                        completo con el nombre — el fondo es claro y se lee. */}
                    <Link href={route('home')} className="mb-8 flex items-center justify-center lg:hidden">
                        <img src="/logo-aurum.png" alt="Aurum Clinic System" className="h-24 w-auto rounded-lg dark:bg-white dark:p-2" />
                    </Link>

                    <div className="mb-6 flex flex-col gap-1.5">
                        <h1 className="font-display text-3xl tracking-tight">{title}</h1>
                        <p className="text-muted-foreground text-sm text-balance">{description}</p>
                    </div>
                    {children}
                </div>
            </div>
        </div>
    );
}
