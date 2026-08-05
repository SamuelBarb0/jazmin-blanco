import { Breadcrumbs } from '@/components/breadcrumbs';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { type BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({ breadcrumbs = [] }: { breadcrumbs?: BreadcrumbItemType[] }) {
    return (
        // `sticky`: en el teléfono la cabecera se iba con el scroll, así que para
        // cambiar de sección había que subir toda la página —larga— y recién ahí
        // pulsar el menú. Fijada arriba, el botón está siempre a un toque.
        // El `top-2` y el borde redondeado en `md` acompañan al `m-2`/`rounded-xl`
        // del contenedor `inset`, para que al fijarse no se salga de la tarjeta.
        // El relleno de zona segura va en el contenedor de FUERA y la altura en
        // el de dentro. Si se pusiera todo junto, el relleno comprimiría la fila
        // dentro de su altura fija en vez de bajarla, y el reloj del teléfono
        // seguiría encima del botón. Así el fondo tapa la barra de estado y la
        // fila queda por debajo.
        <header className="border-sidebar-border/50 bg-background/85 safe-top sticky top-0 z-40 shrink-0 border-b backdrop-blur-md md:top-2 md:rounded-t-xl">
            <div className="flex h-16 items-center gap-2 px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4">
                {/* 44px en móvil: es el mínimo táctil de Apple. Venía en 28 (`h-7`),
                    que para un pulgar es un objetivo diminuto. En escritorio, donde
                    hay puntero, se queda como estaba. */}
                <SidebarTrigger className="-ml-1 size-11 md:size-7" />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
        </header>
    );
}
