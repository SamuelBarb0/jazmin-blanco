import LeadForm from '@/components/lead-form';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Stage, type Tag } from '@/types';
import { Head } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pacientes', href: '/leads' },
    { title: 'Nuevo lead', href: '/leads/create' },
];

export default function CreateLead({ stages, tags, services }: { stages: Stage[]; tags: Tag[]; services: string[] }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Nuevo lead" />
            <div className="mx-auto w-full max-w-3xl p-4">
                <div className="mb-6">
                    <h1 className="font-display text-3xl tracking-tight">Nuevo lead</h1>
                    <p className="text-muted-foreground">Registra un paciente potencial en el pipeline.</p>
                </div>
                <LeadForm stages={stages} tags={tags} services={services} />
            </div>
        </AppLayout>
    );
}
