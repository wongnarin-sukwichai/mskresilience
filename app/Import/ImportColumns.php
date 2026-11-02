<?php

namespace App\Import;

use App\Enums\WorkGroup;

/**
 * The column headers of each กลุ่มงาน's import sheet — the single source of truth for the downloadable template
 * and (later) the header check on upload. Taken from the client's data_input_mcr2030_v4.xlsx, with these
 * deliberate differences:
 * - Headers are trimmed (the client file has stray spaces/tabs, e.g. "POPULATION\t\t").
 * - PM2.5 is spelled PM25, matching the internal hazard key (the client file mixes both; the upload check
 *   will accept either).
 * - The event sheet keeps the staff-typed EVENT_ID (owner decision 2026-11-02, reversing an earlier "system
 *   generates it"); the upload check must reject duplicates.
 */
class ImportColumns
{
    /** Columns holding dates, formatted as dates in the template. */
    public const DATE_COLUMNS = ['ASSESSMENT_DATE', 'EVENT_START', 'EVENT_END'];

    /** The community key column — gets the R001–R032 dropdown in the template. */
    public const COMMUNITY_ID = 'COMMUNITY_ID';

    /**
     * @return list<string>
     */
    public static function for(WorkGroup $group): array
    {
        $base = ['ASSESSMENT_DATE', 'COMMUNITY_ID', 'COMMUNITY_NAME'];

        return match ($group) {
            WorkGroup::DisasterEvent => [
                'EVENT_ID', 'COMMUNITY_ID', 'COMMUNITY_NAME', 'EVENT_START', 'EVENT_END', 'PRIMARY_HAZARD', 'RELATED_HAZARDS',
                'MULTI_HAZARD_FLAG', 'EVENT_DESCRIPTION', 'L1_HOUSES_DAMAGED_COUNT', 'L2_INJURY', 'L2_DEATH',
                'L3_DAMAGE_VALUE_THB', 'L4_RECOVERY_DAYS', 'L5_REPEAT_DAMAGE_COUNT', 'R1_OBSERVED', 'R2_OBSERVED',
                'R3_OBSERVED', 'R4_OBSERVED', 'R5_OBSERVED', 'F1_EVENT_TOTAL_BUDGET_THB', 'F2_EVENT_RESPONSE_COST_THB',
                'F3_EVENT_RECOVERY_COST_THB', 'F4_EVENT_RISK_REDUCTION_BUDGET_THB', 'F5_EVENT_RELIEF_COMPENSATION_THB',
            ],
            WorkGroup::Disaster => [...$base,
                'TAMBON', 'AMPHOE', 'PROVINCE', 'POPULATION', 'HOUSEHOLDS', 'AREA_KM2',
                'E_NUMERATOR_COUNT_FLOOD', 'E_NUMERATOR_COUNT_FIRE', 'E_NUMERATOR_COUNT_STORM', 'E_NUMERATOR_COUNT_HEAT',
                'E_NUMERATOR_COUNT_PM25', 'C4_1_VOLUNTEER_PER_1000', 'C4_2_TRAINING_COUNT',
                'C6_1_RESPONSE_VEHICLE_ADEQUACY_SCORE', 'C6_2_LIFESAVING_SCORE', 'C6_3_READINESS_SCORE',
                'C6_4_HAZARD_EQUIPMENT_ADEQUACY_SCORE', 'C7_1_FLOOD_SCORE', 'C7_1_FIRE_SCORE', 'C7_1_STORM_SCORE',
                'C7_2_SAFE_PLACE_SCORE', 'C7_3_DRILL_SCORE', 'C8_1_FORMAL_NETWORK_COUNT', 'C8_2_RESPONSE_HOURS',
                'C8_3_NETWORK_TYPES_SCORE', 'C8_4_JOINT_EXPERIENCE_SCORE', 'R1_RECOVERY_PLAN_SCORE',
                'R2_BUILD_BACK_BETTER_SCORE', 'R3_SOCIOECONOMIC_RECOV_SCORE', 'R4_VULNER_GROUP_RECOV_SCORE',
                'R5_LEARNING_SYS_IMPROV_SCORE', 'M_BUDGET_AVG_3Y',
            ],
            WorkGroup::Health => [...$base,
                'V_BEDRIDDEN_COUNT', 'V_MEDICAL_DEPENDENT_COUNT', 'V_ASSISTED_EVACUATION_COUNT',
                'V_COMMUNICATION_ASSISTANCE_COUNT', 'V_NCD_COUNT',
            ],
            WorkGroup::Welfare => [...$base, 'V_ELDERLY_COUNT', 'V_CHILD_COUNT', 'V_DISABLED_COUNT', 'V_LOW_INCOME_COUNT'],
            WorkGroup::Education => [...$base, 'C4_4_SCHOOL_DRR_EDUCATION_SCORE'],
            WorkGroup::Engineering => [...$base,
                'V_INFRA_GAP_COUNT_FLOOD', 'V_INFRA_GAP_COUNT_FIRE', 'V_INFRA_GAP_COUNT_STORM', 'V_INFRA_GAP_COUNT_HEAT',
                'V_INFRA_GAP_COUNT_PM25', 'C5_1_GREENBLUE_SCORE', 'C5_2_NBS_SCORE', 'C5_3_MAINTENANCE_SCORE',
            ],
            WorkGroup::PublicRelations => [...$base, 'V_WARNING_ACCESS_GAP_SCORE', 'C4_3_RISK_COMM_SCORE'],
            WorkGroup::Kpi => [...$base,
                'KPI_LOW_INCOME_EXIT_VERIFIED_COUNT', 'KPI_LOW_INCOME_FOLLOWUP_COUNT', 'KPI_ELDERLY_PLAN_COUNT',
                'KPI_DISABLED_PLAN_COUNT', 'KPI_CHILD_PLAN_COUNT', 'KPI_MEDICAL_PLAN_READY_COUNT',
                'KPI_BEDRIDDEN_PLAN_READY_COUNT', 'KPI_ASSISTED_EVAC_READY_COUNT',
            ],
        };
    }
}
