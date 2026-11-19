export type GenerationStatus = 'queued' | 'processing' | 'succeeded' | 'failed';

export type Generation = {
    id: number;
    status: GenerationStatus;
    waiting_for_slot: boolean;
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
    estimated_min_usd: string;
    estimated_max_usd: string;
    actual_cost_usd: string | null;
    usage: {
        input_text: number;
        input_image: number;
        output: number;
        reasoning: number;
    } | null;
    image_url: string | null;
    image_expired: boolean;
    error_message: string | null;
    created_at: string | null;
    completed_at: string | null;
};

export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

export type Paginated<T> = {
    data: T[];
    links: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    };
    meta: {
        current_page: number;
        last_page: number;
        from: number | null;
        to: number | null;
        total: number;
        links: PaginationLink[];
    };
};

export type Estimate = {
    min_usd: string;
    max_usd: string;
    tokens: {
        input_text: { min: number; max: number };
        input_image: number;
        output: number;
        reasoning: { min: number; max: number };
    };
};

export type Credits = {
    balance_usd: string;
    reserved_usd: string;
    available_usd: string;
};

export type UsageTotals = {
    generations: number;
    input_text_tokens: number;
    input_image_tokens: number;
    output_tokens: number;
    reasoning_tokens: number;
    charged_usd: string;
    provider_usd: string;
    profit_usd: string;
};

export type PixelforgeUsageDay = {
    generations: number;
    input_text_tokens: number;
    input_image_tokens: number;
    output_tokens: number;
    reasoning_tokens: number;
    cost_usd: string;
};

export type UsageDay = UsageTotals & {
    date: string;
    pixelforge: PixelforgeUsageDay | null;
    matches: boolean | null;
};

export type UsageUserDay = UsageTotals & {
    date: string;
    user_id: number;
    name: string;
    email: string;
};

export type UsageReport = {
    start: string;
    end: string;
    days: UsageDay[];
    users: UsageUserDay[];
    totals: UsageTotals & {
        pixelforge_cost_usd: string | null;
        pixelforge_generations: number | null;
    };
    pixelforge_error: string | null;
    reconciled: boolean | null;
};
