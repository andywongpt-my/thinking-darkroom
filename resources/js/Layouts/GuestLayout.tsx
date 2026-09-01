import ApplicationLogo from '@/Components/ApplicationLogo';
import { Link } from '@inertiajs/react';
import { PropsWithChildren } from 'react';

export default function Guest({ children }: PropsWithChildren) {
    return (
        <div className="flex min-h-screen flex-col items-center bg-zinc-950 pt-6 sm:justify-center sm:pt-0">
            <div>
                <Link href="/">
                    <ApplicationLogo className="h-20 w-20 fill-current text-zinc-400" />
                </Link>
            </div>

            <div className="mt-6 w-full overflow-hidden bg-zinc-900/60 px-6 py-4 shadow-md ring-1 ring-zinc-800 sm:max-w-md sm:rounded-lg">
                {children}
            </div>
        </div>
    );
}
