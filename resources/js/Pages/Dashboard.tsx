import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import type { PageProps } from '@/types';

interface DashboardProject {
    id: number;
    name: string;
    status: string;
    photo_count: number;
    pending_proposals: number;
    url: string;
}

type DashboardProps = PageProps<{
    projects: DashboardProject[];
}>;

export default function Dashboard({ projects }: DashboardProps) {
    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Dashboard
                </h2>
            }
        >
            <Head title="Dashboard" />

            <div className="py-12">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    {projects.length === 0 ? (
                        <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                            <div className="p-6 text-gray-900">
                                No projects yet. Ask your photographer account owner to add you, or
                                log in as a photographer to create one.
                            </div>
                        </div>
                    ) : (
                        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                            {projects.map((p) => (
                                <Link
                                    key={p.id}
                                    href={p.url}
                                    className="block rounded-xl border border-gray-200 bg-white p-5 shadow-sm transition hover:border-gray-300 hover:shadow"
                                    data-testid={`dashboard-project-${p.id}`}
                                >
                                    <h3 className="font-semibold text-gray-900">{p.name}</h3>
                                    <p className="mt-1 text-xs uppercase tracking-wide text-gray-400">
                                        {p.status}
                                    </p>
                                    <div className="mt-4 flex gap-4 text-sm text-gray-600">
                                        <span>{p.photo_count} photos</span>
                                        <span>
                                            {p.pending_proposals > 0
                                                ? `${p.pending_proposals} pending proposal${p.pending_proposals === 1 ? '' : 's'}`
                                                : 'No pending proposals'}
                                        </span>
                                    </div>
                                </Link>
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
