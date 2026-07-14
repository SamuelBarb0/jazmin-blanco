import { SVGAttributes } from 'react';

export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
            {/* Destello principal — símbolo de estética / cuidado */}
            <path d="M12 2c.5 4.2 2.8 6.5 7 7-4.2.5-6.5 2.8-7 7-.5-4.2-2.8-6.5-7-7 4.2-.5 6.5-2.8 7-7z" />
            {/* Destello secundario */}
            <path d="M18.5 14c.25 1.7 1.3 2.75 3 3-1.7.25-2.75 1.3-3 3-.25-1.7-1.3-2.75-3-3 1.7-.25 2.75-1.3 3-3z" />
        </svg>
    );
}
