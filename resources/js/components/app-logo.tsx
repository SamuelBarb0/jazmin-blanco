import AppLogoIcon from './app-logo-icon';

export default function AppLogo() {
    return (
        <>
            {/* Teja blanca a propósito: el emblema lleva trazos azul oscuro que
                desaparecerían sobre el sidebar oscuro. */}
            <div className="flex aspect-square size-8 shrink-0 items-center justify-center rounded-md bg-white ring-1 ring-black/5">
                <AppLogoIcon className="size-7" />
            </div>
            {/* Versión clara del logotipo: el sidebar es azul oscuro en los dos
                temas, y la palabra original es azul marino — se perdería. */}
            <img
                src="/logo-aurum-wordmark-light.png"
                alt="Aurum Clinic System"
                className="ml-2 h-6 w-auto group-data-[collapsible=icon]:hidden"
            />
        </>
    );
}
