import ServiceForm from '@/components/service-form';
import ServiceMediaManager from '@/components/service-media-manager';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Service, type ServiceMedia } from '@/types';
import { Head } from '@inertiajs/react';

export default function EditService({ service, media, aiConfigured }: { service: Service; media: ServiceMedia[]; aiConfigured: boolean }) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servicios', href: '/services' },
        { title: service.name, href: `/services/${service.id}/edit` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Editar · ${service.name}`} />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4">
                <div>
                    <h1 className="font-display text-3xl tracking-tight">Editar servicio</h1>
                    <p className="text-muted-foreground">Actualiza los datos, el material visual o regenera el contexto con IA.</p>
                </div>
                <ServiceForm service={service} aiConfigured={aiConfigured} />
                <ServiceMediaManager service={service} media={media} />
            </div>
        </AppLayout>
    );
}
