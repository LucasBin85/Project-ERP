import { router } from '@inertiajs/vue3'
import { watch } from 'vue'
import { route } from 'ziggy-js'

type AutoFiltersOptions = {
    delay?: number
    cleanUrl?: boolean
    preserveState?: boolean
    preserveScroll?: boolean
    beforeFilter?: (() => boolean) | null
}

export function useAutoFilters<T extends Record<string, unknown>>(
    form: T,
    routeName: string,
    options: AutoFiltersOptions = {},
): void {
    const {
        delay = 300,
        cleanUrl = true,
        preserveState = true,
        preserveScroll = true,
        beforeFilter = null,
    } = options

    let timeout: ReturnType<typeof setTimeout> | null = null

    watch(
        form,
        () => {
            if (beforeFilter && beforeFilter() === false) {
                return
            }

            if (timeout) {
                clearTimeout(timeout)
            }

            timeout = setTimeout(() => {
                router.get(
                    route(routeName),
                    { ...form },
                    {
                        preserveState,
                        preserveScroll,
                        replace: true,
                        onSuccess: () => {
                            if (cleanUrl) {
                                window.history.replaceState(
                                    {},
                                    '',
                                    route(routeName),
                                )
                            }
                        },
                    },
                )
            }, delay)
        },
        { deep: true },
    )
}