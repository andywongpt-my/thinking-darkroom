import { useEffect, useRef, useState } from 'react';
import { WebmcpRegistry } from './registry';
import type { RegistrySnapshot } from './registry';

export interface WebmcpRegistryBinding {
    registry: WebmcpRegistry | null;
    snapshot: RegistrySnapshot | null;
    eligibleForExecution: boolean;
    refresh: () => void;
}

/**
 * Binds a WebmcpRegistry to a React component:
 *  - creates the registry + registers base tools on mount
 *  - reconciles the dynamic EXECUTE tool whenever the eligible approved
 *    proposal id changes (approval → appears; execution → disappears)
 *  - exposes the live diagnostics snapshot for the Agent Activity panel
 *  - disposes (unregisters every tool, fires AbortControllers) on unmount
 */
export function useWebmcpRegistry(
    projectId: number,
    eligibleProposalId: number | null,
): WebmcpRegistryBinding {
    const registryRef = useRef<WebmcpRegistry | null>(null);
    const [snapshot, setSnapshot] = useState<RegistrySnapshot | null>(null);
    const [, setTick] = useState(0);

    useEffect(() => {
        const registry = new WebmcpRegistry(projectId);
        registryRef.current = registry;
        const unsub = registry.subscribe(setSnapshot);
        registry.begin();

        return () => {
            unsub();
            registry.dispose();
            registryRef.current = null;
        };
    }, [projectId]);

    useEffect(() => {
        registryRef.current?.reconcileEligibleProposal(eligibleProposalId);
    }, [eligibleProposalId, projectId]);

    return {
        registry: registryRef.current,
        snapshot,
        eligibleForExecution: snapshot?.eligibleForExecution ?? false,
        refresh: () => setTick((t) => t + 1),
    };
}
