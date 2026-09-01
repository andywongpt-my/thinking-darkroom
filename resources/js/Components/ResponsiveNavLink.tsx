import { InertiaLinkProps, Link } from '@inertiajs/react';

export default function ResponsiveNavLink({
    active = false,
    className = '',
    children,
    ...props
}: InertiaLinkProps & { active?: boolean }) {
    const tone = active
        ? 'border-amber-400 bg-zinc-900 text-amber-400 focus:border-amber-400 focus:bg-zinc-900 focus:text-amber-300'
        : 'border-transparent text-zinc-300 hover:border-zinc-700 hover:bg-zinc-900 hover:text-zinc-100 focus:border-zinc-700 focus:bg-zinc-900 focus:text-zinc-100';

    return (
        <Link
            {...props}
            className={`flex w-full items-start border-l-4 py-2 pe-4 ps-3 ${tone} text-base font-medium transition duration-150 ease-in-out focus:outline-none ${className}`}
        >
            {children}
        </Link>
    );
}
