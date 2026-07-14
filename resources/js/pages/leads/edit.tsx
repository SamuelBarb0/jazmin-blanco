import LeadForm from '@/components/lead-form';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Lead, type Stage, type Tag } from '@/types';
import { Head } from '@inertiajs/react';

export default function EditLead({ lead, stages, tags, services }: { lead: Lead; stages: Stage[]; tags: Tag[]; services: string[] }) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Pacientes', href: '/leads' },
        { title: lead.name, href: `/leads/${lead.id}/edit` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Editar · ${lead.name}`} />
            <div className="mx-auto w-full max-w-3xl p-4">
                <div className="mb-6">
                    <h1 className="font-display text-3xl tracking-tight">Editar lead</h1>
                    <p className="text-muted-foreground">Actualiza los datos del paciente.</p>
                </div>
                <LeadForm lead={lead} stages={stages} tags={tags} services={services} />
            </div>
        </AppLayout>
    );
}
