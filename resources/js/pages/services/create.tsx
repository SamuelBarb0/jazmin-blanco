import ServiceForm from '@/components/service-form';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Servicios', href: '/services' },
    { title: 'Nuevo servicio', href: '/services/create' },
];

export default function CreateService({ aiConfigured }: { aiConfigured: boolean }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Nuevo servicio" />
            <div className="mx-auto w-full max-w-3xl p-4">
                <div className="mb-6">
                    <h1 className="font-display text-3xl tracking-tight">Nuevo servicio</h1>
                    <p className="text-muted-foreground">Registra un servicio y deja que la IA redacte su presentación.</p>
                </div>
                <ServiceForm aiConfigured={aiConfigured} />
                <p className="text-muted-foreground mt-4 text-sm">
                    💡 Después de guardar podrás agregar fotos y videos del servicio para que el asistente los envíe a los pacientes.
                </p>
            </div>
        </AppLayout>
    );
}
