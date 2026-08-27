/**
 * WebMCP challenge — frontend WebMCP layer.
 *
 * Public entry point: exposes the registry + feature detection so the
 * workspace page can drive tool lifecycle without scattering
 * `document.modelContext.registerTool` calls through components.
 */
export { WebmcpRegistry } from './registry';
export type { RegistrySnapshot } from './registry';
export { isWebmcpAvailable, getModelContext } from './model-context';
export { webmcpApi } from './api';
export type * from './tool-types';
