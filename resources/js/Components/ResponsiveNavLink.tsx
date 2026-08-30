import { InertiaLinkProps, Link } from '@inertiajs/react';

export default function ResponsiveNavLink({
    active = false,
    dark = false,
    className = '',
    children,
    ...props
}: InertiaLinkProps & { active?: boolean; dark?: boolean }) {
    const tone = dark
        ? active
            ? 'border-amber-400 bg-zinc-900 text-amber-400 focus:border-amber-400 focus:bg-zinc-900 focus:text-amber-300'
            : 'border-transparent text-zinc-300 hover:border-zinc-700 hover:bg-zinc-900 hover:text-zinc-100 focus:border-zinc-700 focus:bg-zinc-900 focus:text-zinc-100'
        : active
          ? 'border-indigo-400 bg-indigo-50 text-indigo-700 focus:border-indigo-700 focus:bg-indigo-100 focus:text-indigo-800'
          : 'border-transparent text-gray-600 hover:border-gray-300 hover:bg-gray-50 hover:text-gray-800 focus:border-gray-300 focus:bg-gray-50 focus:text-gray-800';

    return (
        <Link
            {...props}
            className={`flex w-full items-start border-l-4 py-2 pe-4 ps-3 ${tone} text-base font-medium transition duration-150 ease-in-out focus:outline-none ${className}`}
        >
            {children}
        </Link>
    );
}
