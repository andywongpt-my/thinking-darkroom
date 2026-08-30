import { InertiaLinkProps, Link } from '@inertiajs/react';

export default function NavLink({
    active = false,
    dark = false,
    className = '',
    children,
    ...props
}: InertiaLinkProps & { active: boolean; dark?: boolean }) {
    const base =
        'inline-flex items-center border-b-2 px-1 pt-1 text-sm font-medium leading-5 transition duration-150 ease-in-out focus:outline-none ';

    const tone = dark
        ? active
            ? 'border-amber-400 text-zinc-100 focus:border-amber-300'
            : 'border-transparent text-zinc-400 hover:border-zinc-700 hover:text-zinc-200 focus:border-zinc-700 focus:text-zinc-200'
        : active
          ? 'border-indigo-400 text-gray-900 focus:border-indigo-700'
          : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 focus:border-gray-300 focus:text-gray-700';

    return (
        <Link {...props} className={base + tone + className}>
            {children}
        </Link>
    );
}
