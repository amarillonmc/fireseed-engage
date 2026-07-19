# 《メガミエンゲイジ》逐技能机制矩阵 / Per-skill mechanism matrix

本文件逐条审计 `doc/me_skills.md` 的 470 个技能，并保持与来源完全相同的顺序和名称。主分类沿用 `doc/me_skill_mechanism_classification.md` 的既有分配；本文件补足该文档附录未展开的复合机制、生命周期、数值依赖、用途域和迁移边界。

This file audits all 470 skills in `doc/me_skills.md` in exact source order. Primary categories are preserved from `doc/me_skill_mechanism_classification.md`; this matrix expands the appendix with composite mechanisms, lifecycle, value dependencies, domains, and migration boundaries.

## 1. 读法与约束 / Reading rules

机器可读行使用九列：

`来源序号 | 来源名称 | 既有主分类 | 规范模板 | 完整机制/效果标签 | 生命周期/CT | 数值依赖 | 用途域 | 迁移状态:原因`

- 机制标签使用分类规范中的正式机制名；括号内记录 stat、兵种、资源、条件或来源作用域。分号表示一个复合技能的并列组件，任何组件都不能在移植时静默丢弃。
- `active?:H:MM` 表示来源有非零 CT，但没有给出 duration 或“下一次出征消费”字段；问号是迁移阻断标记。`timed?:H:MM(no-duration)` 只表示来源明确写了“发动中”，仍不把 CT 当作持续时间。
- `continuous:00:00` 表示来源明确“自動発動”且 CT 为零。`instant:H:MM` 表示立即动作，`event:*` 表示依赖事件，`unknown:-` 表示来源为空。
- 数值依赖以来源公式为准：`fixed`、`COST`、`intelligence`、`COST+intelligence` 可并列；后者表示 `COST*a + intelligence*b`，不是乘积。`source-stat` 表示来源提到其他属性却缺少系数。
- 状态为 `implemented`、`adapted`、`placeholder` 或 `mixed`。`mixed` 表示基础机制已实现但生命周期不完整，或复合技能同时含可执行和占位组件。
- 原作角色与オートマタ系数不同、同据点光环、上位兵种等边界保留为显式占位标签；不取平均、不降级兵种、不删除子效果。

## 2. 模板与标签索引 / Template and tag index

模板只表达机制组合，不包含数值。两个技能共享模板，表示后台应复用同一组合接口并仅替换等级曲线、CT 曲线和名称。

| 模板前缀 | 组合含义 |
|---|---|
| `T-ATK`, `T-SPD`, `T-DEF` | 单一攻击、速度或防御修正；具体作用域见机制标签。 |
| `T-ATKSPD`, `T-DEFSPD` | 攻击+速度或防御+速度复合。 |
| `T-SPLIT-*` | 角色与オートマタ系数不同；当前有显式占位边界。 |
| `T-ELEMENT-*` | 按同势力参战角色数叠层；本项目元素映射为大地→green、太陽→day、星→bright、月→night。 |
| `T-SIEGE-*` | 攻城百分比、倍率、固定加值及速度/返程复合。 |
| `T-COLLECT`, `T-TRAIN`, `T-INFRA` | 地图采集、训练、建设/据点机制。 |
| `T-INSTANT-*`, `T-EVENT-*` | 立即动作或事件动作。 |
| `T-CUSTOM` | 仍由该行完整机制标签重建，不表示机制未知。 |

常用占位标签包括 `resource_collection_percent`、`treasure_find_chance`、`treasure_empty_rate_reduction`、`territory_popularity_damage`、`territory_popularity_restore`、`tension_change`、`resource_conversion_rate`、`reinforcement_only_modifier`、`skirmish_only_modifier`、`unit_transfer_on_reinforcement`、`adjacent_allied_territory_scaling`、`gender_roster_scaling`、`defender_general_damage`、`hp_cost_on_attack`、`heal_on_battle_success`、`base_damage_reduction`、`waiting_roster_heal` 和 `advanced_unit_scope`。横切占位使用 `same_base_aura`、`source_base_dispatch_scope`、`character_automata_scope`、`character_automata_split_modifier`、`secondary_stat_scaling` 与 `distance_scaling`。其中 `character_automata_scope` 表示来源只作用于角色或只作用于オートマタ；`character` / `automata` 是来源作用域标签，不是当前注册表可接受的 `unit_type`。只有来源对“キャラと全オートマタ”使用相同系数、语义覆盖整军时，矩阵才规范化为 `all`。

## 3. 逐技能矩阵 / Per-skill matrix

<!-- ME_SKILL_MATRIX_BEGIN -->
001|-終-の領域|CR|T-ATK-DIST|army_stat_percent(attack,character);character_automata_scope;condition(distance,lte,10)|active?:60:00|COST|battle/short-range|mixed:source-character-scope-unsupported;lifecycle-unresolved
002|2.14の告白|CB|T-ATK-CHAR|army_stat_percent(attack,character);character_automata_scope|active?:60:00|COST|battle|mixed:source-character-scope-unsupported;lifecycle-unresolved
003|××料理娘|CB|T-SPLIT-ATK|army_stat_percent(attack,automata);army_stat_percent(attack,character);character_automata_split_modifier|active?:60:00|COST|battle|mixed:character-automata-split
004|うわ少女つよい|CB|T-ATKSPD-CHAR|army_stat_percent(attack,character);army_stat_percent(speed,character);character_automata_scope|active?:60:00|COST|battle/march|mixed:source-character-scope-unsupported;lifecycle-unresolved
005|お菓子頂戴!|IR|T-ATKSPD-REWARD|army_stat_percent(attack,all);army_stat_percent(speed,all);battle_reward_resource_percent|active?+event:30:00|COST|battle/march/reward|mixed:implemented+placeholder(no-battle-reward-hook)
006|くろまほう|SG|T-SIEGE-FLAT|army_stat_percent(attack,golem);army_stat_percent(speed,golem);army_siege_damage_flat|active?:10:00|fixed|battle/march/siege|mixed:mechanisms-ready;lifecycle-unresolved
007|それ頂戴☆|IR|T-ATKSPD-REWARD|army_stat_percent(attack,all);army_stat_percent(speed,all);battle_reward_resource_percent|active?+event:30:00|COST|battle/march/reward|mixed:implemented+placeholder(no-battle-reward-hook)
008|どこでも剣士|CB|T-SPD-UNIT|army_stat_percent(speed,rook)|active?:20:00|COST|march|mixed:mechanism-ready;lifecycle-unresolved
009|どこでも司祭|CB|T-SPD-UNIT|army_stat_percent(speed,bishop)|active?:20:00|COST|march|mixed:mechanism-ready;lifecycle-unresolved
010|どこでも歩兵|CB|T-SPD-UNIT|army_stat_percent(speed,pawn)|active?:20:00|COST|march|mixed:mechanism-ready;lifecycle-unresolved
011|どこでも騎士|CB|T-SPD-UNIT|army_stat_percent(speed,knight)|active?:20:00|COST|march|mixed:mechanism-ready;lifecycle-unresolved
012|はーとふるボム|HT|T-ATK-HPCOST|army_stat_percent(attack,character);character_automata_scope;hp_cost_on_attack|active?+event:40:00|COST;fixed|battle/dispatch|mixed:source-character-scope+placeholder(no-audited-hp-cost)
013|みんなのユノ|CB|T-ATKSPD-CHAR|army_stat_percent(attack,character);army_stat_percent(speed,character);character_automata_scope|active?:60:00|COST|battle/march|mixed:source-character-scope-unsupported;lifecycle-unresolved
014|わわわワープ|RF|T-REINFORCE-SPD|army_stat_percent(speed,all);reinforcement_only_modifier|event:10:00|COST|reinforcement/march|placeholder:no-authoritative-reinforcement-identity
015|アマテラス|CB|T-ATK-MULSPD|army_stat_percent(attack,all);army_stat_multiplier(speed,all,0.5)|active?:60:00|COST;fixed|battle/march|mixed:mechanisms-ready;lifecycle-unresolved
016|ソラの創造主|CB|T-SPLIT-ATKSPD|army_stat_percent(attack,automata);army_stat_percent(attack,character);army_stat_percent(speed,all);character_automata_split_modifier|active?:10:00|COST|battle/march|mixed:character-automata-split;lifecycle-unresolved
017|ダッシュ！|CB|T-SPD-CHAR|army_stat_percent(speed,character);character_automata_scope|continuous:00:00|COST|march|mixed:source-character-scope-unsupported
018|ハツラツ少女|CB|T-ATKSPD-CHAR|army_stat_percent(attack,character);army_stat_percent(speed,character);character_automata_scope|continuous:00:00|COST|battle/march|mixed:source-character-scope-unsupported
019|ブルマミクス|CB|T-SPLIT-ATKSPD|army_stat_percent(attack,automata);army_stat_percent(attack,character);army_stat_percent(speed,all);character_automata_split_modifier|continuous:00:00|COST|battle/march|mixed:character-automata-split
020|マナの幻想郷|IR|T-INSTANT-RES|grant_resources(all)|instant:72:00|COST|activation/resources|adapted:four-source-mana-to-six-resources
021|マナの洗礼|IR|T-INSTANT-RES|grant_resources(all)|instant:72:00|COST|activation/resources|adapted:four-source-mana-to-six-resources
022|マナ変換法|TC|T-CONVERT|resource_conversion_rate|active?:20:00|COST|resource-conversion|placeholder:no-conversion-facility
023|マナ大漁警報|RC|T-COLLECT|resource_collection_percent(wind,fire,water,earth)|active?:20:00|COST+intelligence|resource-map|placeholder:no-resource-node-assignment
024|マナ応用法|TC|T-CONVERT|resource_conversion_rate|active?:20:00|COST|resource-conversion|placeholder:no-conversion-facility
025|メグの恵み|RC|T-COLLECT|resource_collection_percent(wind,fire,water,earth)|active?:60:00|COST|resource-map|placeholder:no-resource-node-assignment
026|七聖の円環|DF|T-DEF-ALL|army_stat_percent(defense,all)|active?:48:00|COST+intelligence|battle/defense|mixed:mechanism-ready;lifecycle-unresolved
027|万死の境界線|CB|T-ATK-CHAR|army_stat_percent(attack,character);character_automata_scope|active?:60:00|COST|battle|mixed:source-character-scope-unsupported;lifecycle-unresolved
028|三千世界|CB|T-ATK-MULSPD|army_stat_percent(attack,all);army_stat_multiplier(speed,all,0.5)|active?:60:00|COST;fixed|battle/march|mixed:mechanisms-ready;lifecycle-unresolved
029|上剣士移送|UT|T-TRANSFER-ADV|unit_transfer_on_reinforcement(high-rook);advanced_unit_scope|event:48:00|fixed|reinforcement/ownership|placeholder:no-unit-transfer-and-no-advanced-unit
030|上騎士移送|UT|T-TRANSFER-ADV|unit_transfer_on_reinforcement(high-knight);advanced_unit_scope|event:48:00|fixed|reinforcement/ownership|placeholder:no-unit-transfer-and-no-advanced-unit
031|不動の聖刻|BA|T-SELF-BASE-DEF|army_stat_percent(defense,character);character_automata_scope;same_base_aura|continuous:00:00|COST|city/defense|placeholder:no-character-scope-and-no-source-same-base-aura
032|世界の天啓|CB|T-ATK-ALL|army_stat_percent(attack,all)|continuous:00:00|COST+intelligence|battle|implemented:continuous;same-source-coefficient-normalized-to-all
033|久遠の彼方へ|CB|T-ATKSPD-CHAR|army_stat_percent(attack,character);army_stat_percent(speed,character);character_automata_scope|active?:60:00|COST|battle/march|mixed:source-character-scope-unsupported;lifecycle-unresolved
034|乙女の輝き|CB|T-ATK-ALL|army_stat_percent(attack,all)|active?:10:00|COST|battle|mixed:mechanism-ready;lifecycle-unresolved
035|低廉錬成術|TC|T-TRAIN-COST|city_training_cost_reduction_percent(rook,bishop,knight)|active?:20:00|COST|training/cost|mixed:mechanism-ready;lifecycle-unresolved
036|偵察募集|TC|T-TRAIN-SPEED-ADV|city_training_speed_percent(scout);advanced_unit_scope(high-scout)|active?:10:00|COST|training|mixed:implemented+placeholder(advanced-unit)
037|先駆突撃|CB|T-ATKSPD-CHAR|army_stat_percent(attack,character);army_stat_percent(speed,character);character_automata_scope|active?:10:00|COST|battle/march|mixed:source-character-scope-unsupported;lifecycle-unresolved
038|先駆雷撃|CB|T-ATKSPD-CHAR|army_stat_percent(attack,character);army_stat_percent(speed,character);character_automata_scope|active?:10:00|COST|battle/march|mixed:source-character-scope-unsupported;lifecycle-unresolved
039|光翼の疾風|CR|T-DIST-SCALE|army_stat_percent(attack,all);army_stat_percent(speed,all);distance_scaling;source-placeholder-values|active?:30:00|COST|battle/march/distance|mixed:implemented+placeholder(distance-value-scaling);source-anomaly
040|全員でかーん!|BA|T-SAMEBASE-ATK|army_stat_percent(attack,all);same_base_aura|continuous:00:00|COST|city/battle|placeholder:no-source-same-base-aura
041|全員でどかーん|BA|T-SAMEBASE-ATK|army_stat_percent(attack,all);same_base_aura|continuous:00:00|COST|city/battle|placeholder:no-source-same-base-aura
042|全員でどごーん|BA|T-SAMEBASE-ATK|army_stat_percent(attack,all);same_base_aura|continuous:00:00|COST|city/battle|placeholder:no-source-same-base-aura
043|全員でどーん!|BA|T-SAMEBASE-ATK|army_stat_percent(attack,all);same_base_aura|continuous:00:00|COST|city/battle|placeholder:no-source-same-base-aura
044|全員でばーん!|BA|T-SAMEBASE-ATK|army_stat_percent(attack,all);same_base_aura|continuous:00:00|COST|city/battle|placeholder:no-source-same-base-aura
045|全軍堅守|DF|T-DEF-ALL|army_stat_percent(defense,all)|active?:10:00|COST|battle/defense|mixed:mechanism-ready;lifecycle-unresolved
046|全軍援護|RF|T-REINFORCE-DEFSPD|army_stat_percent(defense,all);army_stat_percent(speed,all);reinforcement_only_modifier|event:auto/00:00|COST|reinforcement/battle/march|placeholder:no-authoritative-reinforcement-identity
047|共防の帳|CR|T-ADJACENT-DEF|army_stat_percent(defense,all);adjacent_allied_territory_scaling|active?:36:00|COST;fixed|battle/defense/map|mixed:implemented+placeholder(no-adjacency-snapshot)
048|初戦の勢い|CB|T-ATK-CHAR|army_stat_percent(attack,character);character_automata_scope|active?:10:00|COST|battle|mixed:source-character-scope-unsupported;lifecycle-unresolved
049|刹那光刃|CB|T-ATK-CHAR|army_stat_percent(attack,character);character_automata_scope|active?:20:00|COST|battle|mixed:source-character-scope-unsupported;lifecycle-unresolved
050|刻司ル極翼|CB|T-ATKSPD-ALL|army_stat_percent(attack,all);army_stat_percent(speed,all)|active?:60:00|COST|battle/march|mixed:mechanisms-ready;lifecycle-unresolved
051|剣の丘|CB|T-ATK-UNIT|army_stat_percent(attack,rook)|active?:60:00|COST|battle|mixed:mechanism-ready;lifecycle-unresolved
052|剣士の光速|CB|T-SPD-UNIT|army_stat_percent(speed,rook)|active?:10:00|COST|march|mixed:mechanism-ready;lifecycle-unresolved
053|剣士の剛守|DF|T-DEF-UNIT|army_stat_percent(defense,rook)|active?:10:00|COST|battle/defense|mixed:mechanism-ready;lifecycle-unresolved
054|剣士の加速|CB|T-SPD-UNIT|army_stat_percent(speed,rook)|active?:10:00|COST|march|mixed:mechanism-ready;lifecycle-unresolved
055|剣士の喝采|BA|T-TIMED-ATK-UNIT|army_stat_percent(attack,rook)|timed?:48:00(no-duration)|COST|battle|mixed:mechanism-ready;duration-missing
056|剣士の堅守|DF|T-DEF-UNIT|army_stat_percent(defense,rook)|active?:10:00|COST|battle/defense|mixed:mechanism-ready;lifecycle-unresolved
057|剣士の境地|CB|T-ATKSPD-UNIT|army_stat_percent(attack,rook);army_stat_percent(speed,rook)|continuous:00:00|COST|battle/march|implemented:continuous
058|剣士の士気|CB|T-ATK-UNIT|army_stat_percent(attack,rook)|continuous:00:00|COST|battle|implemented:continuous
059|剣士の声援|BA|T-TIMED-ATK-UNIT|army_stat_percent(attack,rook)|timed?:48:00(no-duration)|COST|battle|mixed:mechanism-ready;duration-missing
060|剣士の奥義|CB|T-ATKSPD-UNIT|army_stat_percent(attack,rook);army_stat_percent(speed,rook)|active?:10:00|COST|battle/march|mixed:mechanisms-ready;lifecycle-unresolved
061|剣士の守備|DF|T-DEF-UNIT|army_stat_percent(defense,rook)|active?:10:00|COST|battle/defense|mixed:mechanism-ready;lifecycle-unresolved
062|剣士の強襲|CB|T-ATKSPD-UNIT|army_stat_percent(attack,rook);army_stat_percent(speed,rook)|active?:10:00|COST|battle/march|mixed:mechanisms-ready;lifecycle-unresolved
063|剣士の抜刀|CR|T-ADJACENT-ATK-UNIT|army_stat_percent(attack,rook);adjacent_allied_territory_scaling|active?:50:00|COST;fixed|battle/map|mixed:implemented+placeholder(no-adjacency-snapshot)
064|剣士の援護|RF|T-REINFORCE-DEFSPD-UNIT|army_stat_percent(defense,rook);army_stat_percent(speed,rook);reinforcement_only_modifier|event:auto/00:00|COST|reinforcement/battle/march|placeholder:no-authoritative-reinforcement-identity
065|剣士の攻撃|CB|T-ATK-UNIT|army_stat_percent(attack,rook)|active?:10:00|COST|battle|mixed:mechanism-ready;lifecycle-unresolved
066|剣士の気迫|PP|T-ATK-POPULARITY|army_stat_percent(attack,rook);territory_popularity_damage|active?:10:00|COST|battle/territory-popularity|mixed:implemented+placeholder(no-popularity-state)
067|剣士の焚火|RC|T-COLLECT-TRAIN|resource_collection_percent(fire);city_training_speed_percent(rook)|active?:10:00|COST|resource-map/training|mixed:implemented+placeholder(no-resource-node-assignment);lifecycle-unresolved
068|剣士の神器|CB|T-ATK-UNIT|army_stat_percent(attack,rook)|continuous:00:00|COST|battle|implemented:continuous
069|剣士の神撃|CB|T-ATK-UNIT|army_stat_percent(attack,rook)|active?:10:00|COST|battle|mixed:mechanism-ready;lifecycle-unresolved
070|剣士の突攻|CB|T-ATKSPD-UNIT|army_stat_percent(attack,rook);army_stat_percent(speed,rook)|active?:10:00|COST|battle/march|mixed:mechanisms-ready;lifecycle-unresolved
071|剣士の衝撃|CB|T-ATK-UNIT|army_stat_percent(attack,rook)|active?:10:00|COST|battle|mixed:mechanism-ready;lifecycle-unresolved
072|剣士の覇気|CB|T-ATK-UNIT|army_stat_percent(attack,rook)|continuous:00:00|COST|battle|implemented:continuous
073|剣士の誓い|CB|T-ATKSPD-MIXEDSCOPE|army_stat_percent(attack,rook);army_stat_percent(attack,non-rook);army_stat_percent(speed,non-rook)|active?:30:00|COST|battle/march|mixed:non-unit-scope-needs-expansion;lifecycle-unresolved
074|剣士の豪撃|CB|T-ATK-UNIT|army_stat_percent(attack,rook)|active?:10:00|COST|battle|mixed:mechanism-ready;lifecycle-unresolved
075|剣士の超光速|CB|T-SPD-UNIT|army_stat_percent(speed,rook)|active?:10:00|COST|march|mixed:mechanism-ready;lifecycle-unresolved
076|剣士の超神器|CB|T-ATK-UNIT|army_stat_percent(attack,rook)|continuous:00:00|COST|battle|implemented:continuous
077|剣士の超神撃|CB|T-ATK-UNIT|army_stat_percent(attack,rook)|active?:30:00|COST|battle|mixed:mechanism-ready;lifecycle-unresolved
078|剣士の速攻|CB|T-SPD-UNIT|army_stat_percent(speed,rook)|active?:10:00|COST|march|mixed:mechanism-ready;lifecycle-unresolved
079|剣士の進軍|CB|T-ATKSPD-UNIT|army_stat_percent(attack,rook);army_stat_percent(speed,rook)|active?:10:00|COST|battle/march|mixed:mechanisms-ready;lifecycle-unresolved
080|剣士の闘気|CB|T-ATK-UNIT|army_stat_percent(attack,rook)|continuous:00:00|COST|battle|implemented:continuous
081|剣士の防戦|TC|T-SPLIT-DEF-TRAIN|army_stat_percent(defense,automata);army_stat_percent(defense,character);character_automata_split_modifier;city_training_speed_percent(rook)|active?:10:00|COST+intelligence|battle/defense/training|mixed:character-automata-split;lifecycle-unresolved
082|剣士の防衛陣|DF|T-DEF-UNIT|army_stat_percent(defense,rook)|active?:10:00|COST|battle/defense|mixed:mechanism-ready;lifecycle-unresolved
083|剣士の頂き|CB|T-ATKSPD-UNIT|army_stat_percent(attack,rook);army_stat_percent(speed,rook)|continuous:00:00|COST|battle/march|implemented:continuous
084|剣士の高速|CB|T-SPD-UNIT|army_stat_percent(speed,rook)|active?:10:00|COST|march|mixed:mechanism-ready;lifecycle-unresolved
085|剣士募集|TC|T-TRAIN-SPEED|city_training_speed_percent(rook)|active?:10:00|COST|training|mixed:mechanism-ready;lifecycle-unresolved
086|剣士急募|TC|T-TRAIN-SPEED|city_training_speed_percent(rook)|active?:10:00|COST|training|mixed:mechanism-ready;lifecycle-unresolved
087|剣士移送|UT|T-TRANSFER|unit_transfer_on_reinforcement(rook)|event:48:00|fixed|reinforcement/ownership|placeholder:no-unit-transfer-transaction
088|剣霊の叡智|RC|T-COLLECT|resource_collection_percent(wind,fire,earth)|active?:20:00|COST+intelligence|resource-map|placeholder:no-resource-node-assignment
089|剣霊の息吹|RC|T-COLLECT|resource_collection_percent(wind,fire,earth)|active?:10:00|COST|resource-map|placeholder:no-resource-node-assignment
090|剣霊の恵み|RC|T-COLLECT|resource_collection_percent(wind,fire,earth)|active?:10:00|COST|resource-map|placeholder:no-resource-node-assignment
091|勇気の誓い|CB|T-ATK-CHAR|army_stat_percent(attack,character);character_automata_scope|active?:10:00|COST|battle|mixed:source-character-scope-unsupported;lifecycle-unresolved
092|勇気可憐|CB|T-ATK-ALL|army_stat_percent(attack,all)|continuous:00:00|COST|battle|implemented:continuous
093|勇美鼓舞|CR|T-GENDER-ATK|army_stat_percent(attack,automata);army_stat_percent(attack,character);character_automata_split_modifier;gender_roster_scaling(female)|active?:10:00|COST;fixed|battle/roster|mixed:implemented+placeholder(no-gender-field)
094|勇者の才華|CB|T-ATK-CHAR|army_stat_percent(attack,character);character_automata_scope|continuous:00:00|COST|battle|mixed:source-character-scope-unsupported
095|勇者の気質|CB|T-ATK-CHAR|army_stat_percent(attack,character);character_automata_scope|continuous:00:00|COST|battle|mixed:source-character-scope-unsupported
096|勇者の資質|CB|T-ATK-CHAR|army_stat_percent(attack,character);character_automata_scope|continuous:00:00|COST|battle|mixed:source-character-scope-unsupported
097|勝利のバトン|CB|T-ATKSPD-UNIT|army_stat_percent(attack,rook);army_stat_percent(speed,rook)|continuous:00:00|COST|battle/march|implemented:continuous
098|千渦蒼纏刃|HT|T-ATKSPD-ONWIN|army_stat_percent(attack,character);army_stat_percent(speed,character);character_automata_scope;heal_on_battle_success;tension_change(on-success)|active?+event:40:00|COST;fixed|battle/march/post-battle|mixed:source-character-scope+placeholder(no-post-battle-action-snapshot)
099|収奪の紋章|IR|T-ATKSPD-REWARD|army_stat_percent(attack,all);army_stat_percent(speed,all);battle_reward_resource_percent|active?+event:20:00|COST|battle/march/reward|mixed:implemented+placeholder(no-battle-reward-hook)
100|召喚！超剣士|CB|T-ATK-UNIT|army_stat_percent(attack,rook)|active?:60:00|COST|battle|mixed:mechanism-ready;lifecycle-unresolved
101|召喚！超司祭|CB|T-ATK-UNIT|army_stat_percent(attack,bishop)|active?:60:00|COST|battle|mixed:mechanism-ready;lifecycle-unresolved
102|召喚！超歩兵|CB|T-ATK-UNIT|army_stat_percent(attack,pawn)|active?:60:00|COST|battle|mixed:mechanism-ready;lifecycle-unresolved
103|召喚！超騎士|CB|T-ATK-UNIT|army_stat_percent(attack,knight)|active?:60:00|COST|battle|mixed:mechanism-ready;lifecycle-unresolved
104|司祭の光速|CB|T-SPD-UNIT|army_stat_percent(speed,bishop)|active?:10:00|COST|march|mixed:mechanism-ready;lifecycle-unresolved
105|司祭の剛守|DF|T-DEF-UNIT|army_stat_percent(defense,bishop)|active?:10:00|COST|battle/defense|mixed:mechanism-ready;lifecycle-unresolved
106|司祭の加速|CB|T-SPD-UNIT|army_stat_percent(speed,bishop)|active?:10:00|COST|march|mixed:mechanism-ready;lifecycle-unresolved
107|司祭の合掌|CR|T-ADJACENT-ATK-UNIT|army_stat_percent(attack,bishop);adjacent_allied_territory_scaling|active?:50:00|COST;fixed|battle/map|mixed:implemented+placeholder(no-adjacency-snapshot)
108|司祭の喝采|BA|T-TIMED-ATK-UNIT|army_stat_percent(attack,bishop)|timed?:48:00(no-duration)|COST|battle|mixed:mechanism-ready;duration-missing
109|司祭の堅守|DF|T-DEF-UNIT|army_stat_percent(defense,bishop)|active?:10:00|COST|battle/defense|mixed:mechanism-ready;lifecycle-unresolved
110|司祭の境地|CB|T-ATKSPD-UNIT|army_stat_percent(attack,bishop);army_stat_percent(speed,bishop)|continuous:00:00|COST|battle/march|implemented:continuous
111|司祭の士気|CB|T-ATK-UNIT|army_stat_percent(attack,bishop)|continuous:00:00|COST|battle|implemented:continuous
112|司祭の声援|BA|T-TIMED-ATK-UNIT|army_stat_percent(attack,bishop)|timed?:48:00(no-duration)|COST|battle|mixed:mechanism-ready;duration-missing
113|司祭の奥義|CB|T-ATKSPD-UNIT|army_stat_percent(attack,bishop);army_stat_percent(speed,bishop)|active?:10:00|COST|battle/march|mixed:mechanisms-ready;lifecycle-unresolved
114|司祭の守備|DF|T-DEF-UNIT|army_stat_percent(defense,bishop)|active?:10:00|COST|battle/defense|mixed:mechanism-ready;lifecycle-unresolved
115|司祭の強襲|CB|T-ATKSPD-UNIT|army_stat_percent(attack,bishop);army_stat_percent(speed,bishop)|active?:10:00|COST|battle/march|mixed:mechanisms-ready;lifecycle-unresolved
116|司祭の慧心|CB|T-ATKSPD-MIXEDSCOPE|army_stat_percent(attack,bishop);army_stat_percent(attack,non-bishop);army_stat_percent(speed,non-bishop)|active?:30:00|COST|battle/march|mixed:non-unit-scope-needs-expansion;lifecycle-unresolved
117|司祭の援護|RF|T-REINFORCE-DEFSPD-UNIT|army_stat_percent(defense,bishop);army_stat_percent(speed,bishop);reinforcement_only_modifier|event:auto/00:00|COST|reinforcement/battle/march|placeholder:no-authoritative-reinforcement-identity
118|司祭の攻撃|CB|T-ATK-UNIT|army_stat_percent(attack,bishop)|active?:10:00|COST|battle|mixed:mechanism-ready;lifecycle-unresolved
119|司祭の激励|BA|T-TIMED-ATK-UNIT|army_stat_percent(attack,bishop)|timed?:48:00(no-duration)|COST|battle|mixed:mechanism-ready;duration-missing
120|司祭の神器|CB|T-ATK-UNIT|army_stat_percent(attack,bishop)|continuous:00:00|COST|battle|implemented:continuous
121|司祭の神撃|CB|T-ATK-UNIT|army_stat_percent(attack,bishop)|active?:10:00|COST|battle|mixed:mechanism-ready;lifecycle-unresolved
122|司祭の突攻|CB|T-ATKSPD-UNIT|army_stat_percent(attack,bishop);army_stat_percent(speed,bishop)|active?:10:00|COST|battle/march|mixed:mechanisms-ready;lifecycle-unresolved
123|司祭の粛清|CB|T-ATK-UNIT|army_stat_percent(attack,bishop)|active?:20:00|fixed|battle|mixed:mechanism-ready;lifecycle-unresolved
124|司祭の衝撃|CB|T-ATK-UNIT|army_stat_percent(attack,bishop)|active?:10:00|COST|battle|mixed:mechanism-ready;lifecycle-unresolved
125|司祭の覇気|CB|T-ATK-UNIT|army_stat_percent(attack,bishop)|continuous:00:00|COST|battle|implemented:continuous
126|司祭の説法|PP|T-ATK-POPULARITY|army_stat_percent(attack,bishop);territory_popularity_damage|active?:10:00|COST|battle/territory-popularity|mixed:implemented+placeholder(no-popularity-state)
127|司祭の豪撃|CB|T-ATK-UNIT|army_stat_percent(attack,bishop)|active?:10:00|COST|battle|mixed:mechanism-ready;lifecycle-unresolved
128|司祭の超光速|CB|T-SPD-UNIT|army_stat_percent(speed,bishop)|active?:10:00|COST|march|mixed:mechanism-ready;lifecycle-unresolved
129|司祭の超神器|CB|T-ATK-UNIT|army_stat_percent(attack,bishop)|continuous:00:00|COST|battle|implemented:continuous
130|司祭の超神撃|CB|T-ATK-UNIT|army_stat_percent(attack,bishop)|active?:30:00|COST|battle|mixed:mechanism-ready;lifecycle-unresolved
131|司祭の速攻|CB|T-SPD-UNIT|army_stat_percent(speed,bishop)|active?:10:00|COST|march|mixed:mechanism-ready;lifecycle-unresolved
132|司祭の進軍|CB|T-ATKSPD-UNIT|army_stat_percent(attack,bishop);army_stat_percent(speed,bishop)|active?:10:00|COST|battle/march|mixed:mechanisms-ready;lifecycle-unresolved
133|司祭の闘気|CB|T-ATK-UNIT|army_stat_percent(attack,bishop)|continuous:00:00|COST|battle|implemented:continuous
134|司祭の防戦|TC|T-SPLIT-DEF-TRAIN|army_stat_percent(defense,automata);army_stat_percent(defense,character);character_automata_split_modifier;city_training_speed_percent(bishop)|active?:10:00|COST+intelligence|battle/defense/training|mixed:character-automata-split;lifecycle-unresolved
135|司祭の防衛陣|DF|T-DEF-UNIT|army_stat_percent(defense,bishop)|active?:10:00|COST|battle/defense|mixed:mechanism-ready;lifecycle-unresolved
136|司祭の頂き|CB|T-ATKSPD-UNIT|army_stat_percent(attack,bishop);army_stat_percent(speed,bishop)|continuous:00:00|COST|battle/march|implemented:continuous
137|司祭の風見|RC|T-COLLECT-TRAIN|resource_collection_percent(wind);city_training_speed_percent(bishop)|active?:10:00|COST|resource-map/training|mixed:implemented+placeholder(no-resource-node-assignment);lifecycle-unresolved
138|司祭の高速|CB|T-SPD-UNIT|army_stat_percent(speed,bishop)|active?:10:00|COST|march|mixed:mechanism-ready;lifecycle-unresolved
139|司祭募集|TC|T-TRAIN-SPEED|city_training_speed_percent(bishop)|active?:10:00|COST|training|mixed:mechanism-ready;lifecycle-unresolved
140|司祭急募|TC|T-TRAIN-SPEED|city_training_speed_percent(bishop)|active?:10:00|COST|training|mixed:mechanism-ready;lifecycle-unresolved
141|司祭移送|UT|T-TRANSFER|unit_transfer_on_reinforcement(bishop)|event:48:00|fixed|reinforcement/ownership|placeholder:no-unit-transfer-transaction
142|司霊の叡智|RC|T-COLLECT|resource_collection_percent(wind,water,earth)|active?:20:00|COST+intelligence|resource-map|placeholder:no-resource-node-assignment
143|司霊の息吹|RC|T-COLLECT|resource_collection_percent(wind,water,earth)|active?:10:00|COST|resource-map|placeholder:no-resource-node-assignment
144|司霊の恵み|RC|T-COLLECT|resource_collection_percent(wind,water,earth)|active?:10:00|COST|resource-map|placeholder:no-resource-node-assignment
145|土精の大豊穣|RC|T-COLLECT|resource_collection_percent(earth)|active?:10:00|COST|resource-map|placeholder:no-resource-node-assignment
146|土精の息吹|RC|T-COLLECT|resource_collection_percent(earth)|active?:10:00|COST|resource-map|placeholder:no-resource-node-assignment
147|土精の恵み|RC|T-COLLECT|resource_collection_percent(earth)|active?:10:00|COST|resource-map|placeholder:no-resource-node-assignment
148|土精の聖域|RC|T-COLLECT|resource_collection_percent(earth)|active?:20:00|COST|resource-map|placeholder:no-resource-node-assignment
149|土精の豊穣|RC|T-COLLECT|resource_collection_percent(earth)|active?:10:00|COST|resource-map|placeholder:no-resource-node-assignment
150|堅固の聖刻|BA|T-SELF-BASE-DEF|army_stat_percent(defense,character);character_automata_scope;same_base_aura|continuous:00:00|COST|city/defense|placeholder:no-character-scope-and-no-source-same-base-aura
151|夢想詠唱|PP|T-SOURCEBASE-ATK-POPULARITY|army_stat_percent(attack,all);territory_popularity_damage;source_base_dispatch_scope|timed?:60:00(no-duration)|fixed|battle/territory-popularity|mixed:implemented+placeholder(no-popularity-and-source-base-scope);duration-missing
152|大乱の行列|CB|T-ATK-MULSPD|army_stat_percent(attack,all);army_stat_multiplier(speed,all,0.5)|active?:10:00|COST;fixed|battle/march|mixed:mechanisms-ready;lifecycle-unresolved
153|大剣豪の世界|CB|T-ATK-UNIT|army_stat_percent(attack,rook)|active?:60:00|COST|battle|mixed:mechanism-ready;lifecycle-unresolved
154|大司教の楽園|CB|T-ATK-UNIT|army_stat_percent(attack,bishop)|active?:60:00|COST|battle|mixed:mechanism-ready;lifecycle-unresolved
155|大地の士気|CR|T-ELEMENT-ATK|army_element_stat_percent(attack,green)|continuous:00:00|fixed|battle/element-roster|adapted:source-earth-to-green
156|大地の守護|CR|T-ELEMENT-DEF|army_element_stat_percent(defense,green)|active?:48:00|fixed|battle/element-roster/defense|mixed:element-map-ready;lifecycle-unresolved
157|大地の攻撃|CR|T-ELEMENT-ATK|army_element_stat_percent(attack,green)|active?:10:00|fixed|battle/element-roster|mixed:element-map-ready;lifecycle-unresolved
158|大地の神撃|CR|T-ELEMENT-ATK|army_element_stat_percent(attack,green)|active?:10:00|fixed|battle/element-roster|mixed:element-map-ready;lifecycle-unresolved
159|大地の衝撃|CR|T-ELEMENT-ATK|army_element_stat_percent(attack,green)|active?:10:00|fixed|battle/element-roster|mixed:element-map-ready;lifecycle-unresolved
160|大地の覇気|CR|T-ELEMENT-ATK|army_element_stat_percent(attack,green)|continuous:00:00|fixed|battle/element-roster|adapted:source-earth-to-green
161|大地の豪撃|CR|T-ELEMENT-ATK|army_element_stat_percent(attack,green)|active?:10:00|fixed|battle/element-roster|mixed:element-map-ready;lifecycle-unresolved
162|大地の闘気|CR|T-ELEMENT-ATK|army_element_stat_percent(attack,green)|continuous:00:00|fixed|battle/element-roster|adapted:source-earth-to-green
163|天真煉獄刹|CR|T-ATK-DIST|army_stat_percent(attack,character);character_automata_scope;condition(distance,lte,10)|active?:60:00|COST|battle/short-range|mixed:source-character-scope-unsupported;lifecycle-unresolved
164|天賦の才能|CB|T-SPLIT-ATKSPD|army_stat_percent(attack,automata);army_stat_percent(attack,character);army_stat_percent(speed,all);character_automata_split_modifier|active?:10:00|COST+intelligence|battle/march|mixed:character-automata-split;lifecycle-unresolved
165|天駆雷槍|CB|T-ATKSPD-CHAR|army_stat_percent(attack,character);army_stat_percent(speed,character);character_automata_scope|active?:20:00|COST|battle/march|mixed:source-character-scope-unsupported;lifecycle-unresolved
166|太陽の士気|CR|T-ELEMENT-ATK|army_element_stat_percent(attack,day)|continuous:00:00|fixed|battle/element-roster|adapted:source-sun-to-day
167|太陽の守護|CR|T-ELEMENT-DEF|army_element_stat_percent(defense,day)|active?:48:00|fixed|battle/element-roster/defense|mixed:element-map-ready;lifecycle-unresolved
168|太陽の攻撃|CR|T-ELEMENT-ATK|army_element_stat_percent(attack,day)|active?:10:00|fixed|battle/element-roster|mixed:element-map-ready;lifecycle-unresolved
169|太陽の神撃|CR|T-ELEMENT-ATK|army_element_stat_percent(attack,day)|active?:10:00|fixed|battle/element-roster|mixed:element-map-ready;lifecycle-unresolved
170|太陽の衝撃|CR|T-ELEMENT-ATK|army_element_stat_percent(attack,day)|active?:10:00|fixed|battle/element-roster|mixed:element-map-ready;lifecycle-unresolved
171|太陽の覇気|CR|T-ELEMENT-ATK|army_element_stat_percent(attack,day)|continuous:00:00|fixed|battle/element-roster|adapted:source-sun-to-day
172|太陽の豪撃|CR|T-ELEMENT-ATK|army_element_stat_percent(attack,day)|active?:10:00|fixed|battle/element-roster|mixed:element-map-ready;lifecycle-unresolved
173|太陽の闘気|CR|T-ELEMENT-ATK|army_element_stat_percent(attack,day)|continuous:00:00|fixed|battle/element-roster|adapted:source-sun-to-day
174|奪取の紋章|IR|T-ATKSPD-REWARD|army_stat_percent(attack,all);army_stat_percent(speed,all);battle_reward_resource_percent|active?+event:10:00|COST|battle/march/reward|mixed:implemented+placeholder(no-battle-reward-hook)
175|女王の光輝|CB|T-ATK-ALL|army_stat_percent(attack,all)|active?:10:00|COST|battle|mixed:mechanism-ready;lifecycle-unresolved
176|女神の加護|HT|T-INSTANT-WAIT-HEAL-CD|heal_generals(waiting);reduce_skill_cooldowns(waiting);waiting_roster_heal|instant:72:00|fixed|activation/roster|placeholder:no-source-waiting-roster-state
177|女神の叡智|RC|T-COLLECT|resource_collection_percent(wind,fire,water,earth)|active?:20:00|COST+intelligence|resource-map|placeholder:no-resource-node-assignment
178|女神の喝采|BA|T-TIMED-ATK-ALL|army_stat_percent(attack,all)|timed?:48:00(no-duration)|COST|battle|mixed:mechanism-ready;duration-missing
179|女神の声援|BA|T-TIMED-ATK-ALL|army_stat_percent(attack,all)|timed?:48:00(no-duration)|COST|battle|mixed:mechanism-ready;duration-missing
180|女神の征戦|PP|T-ATKSPD-POPULARITY|army_stat_percent(attack,all);army_stat_percent(speed,all);territory_popularity_damage|active?:60:00|COST;fixed|battle/march/territory-popularity|mixed:implemented+placeholder(no-popularity-state)
181|女神の恩寵|HT|T-INSTANT-HEAL|heal_generals(all-owned)|instant:72:00|COST|activation/roster|adapted:source-deck-to-all-owned
182|女神の息吹|RC|T-COLLECT|resource_collection_percent(wind,fire,water,earth)|active?:10:00|COST|resource-map|placeholder:no-resource-node-assignment
183|女神の恵み|RC|T-COLLECT|resource_collection_percent(wind,fire,water,earth)|active?:10:00|COST|resource-map|placeholder:no-resource-node-assignment
184|女神の慈悲|HT|T-INSTANT-WAIT-HEAL|heal_generals(waiting);waiting_roster_heal|instant:72:00|COST|activation/roster|placeholder:no-source-waiting-roster-state
185|女神の慈愛|HT|T-INSTANT-HEAL|heal_generals(all-owned)|instant:72:00|fixed|activation/roster|adapted:all-owned
186|女神の知識|RC|T-COLLECT|resource_collection_percent(wind,fire,water,earth)|active?:20:00|COST+intelligence|resource-map|placeholder:no-resource-node-assignment
187|女神の経典|RC|T-COLLECT|resource_collection_percent(wind,fire,water,earth)|active?:20:00|COST+intelligence|resource-map|placeholder:no-resource-node-assignment
188|女神の覇気|CB|T-ATK-ALL|army_stat_percent(attack,all)|continuous:00:00|COST|battle|implemented:continuous
189|女神の軍勢|CB|T-ATK-MULSPD|army_stat_percent(attack,all);army_stat_multiplier(speed,all,0.5)|active?:40:00|COST;fixed|battle/march|mixed:mechanisms-ready;lifecycle-unresolved
190|女神の闘気|CB|T-ATK-ALL|army_stat_percent(attack,all)|continuous:00:00|COST|battle|implemented:continuous
191|委員長リタ|U|T-UNKNOWN|unknown_effect|unknown:-|unknown|unknown|placeholder:source-effect-and-ct-empty
192|威圧の心得|PP|T-SPD-POPULARITY|army_stat_percent(speed,all);territory_popularity_damage|continuous:00:00|COST;fixed|march/territory-popularity|mixed:implemented+placeholder(no-popularity-state);source-anomaly
193|威圧の才能|PP|T-SPD-POPULARITY|army_stat_percent(speed,all);territory_popularity_damage|continuous:00:00|COST|march/territory-popularity|mixed:implemented+placeholder(no-popularity-state)
194|威圧の真髄|PP|T-SPD-POPULARITY|army_stat_percent(speed,all);territory_popularity_damage|continuous:00:00|COST|march/territory-popularity|mixed:implemented+placeholder(no-popularity-state)
195|威風の聖刻|PP|T-ATK-POPULARITY|army_stat_percent(attack,character);character_automata_scope;territory_popularity_damage|active?:10:00|fixed|battle/territory-popularity|mixed:source-character-scope+placeholder(no-popularity-state);source-label-anomaly
196|威風堂々|PP|T-ATK-POPULARITY|army_stat_percent(attack,all);territory_popularity_damage|active?:10:00|COST|battle/territory-popularity|mixed:implemented+placeholder(no-popularity-state)
197|宇宙の天啓|CB|T-ATK-ALL|army_stat_percent(attack,all)|continuous:00:00|COST+intelligence|battle|implemented:continuous
198|守備の聖刻|BA|T-SELF-BASE-DEF|army_stat_percent(defense,character);character_automata_scope;same_base_aura|continuous:00:00|COST|city/defense|placeholder:no-character-scope-and-no-source-same-base-aura
199|守護の光|BI|T-CROSSBASE-REDUCE|base_damage_reduction(except-source-base);stacking(max-only)|timed?:10:00(no-duration)|COST|city/siege-defense|placeholder:no-cross-base-aura-and-no-max-only-stack
200|守護の神光|BI|T-CROSSBASE-REDUCE|base_damage_reduction(except-source-base);stacking(max-only)|timed?:36:00(no-duration)|COST|city/siege-defense|placeholder:no-cross-base-aura-and-no-max-only-stack
201|守護の聖光|BI|T-CROSSBASE-REDUCE|base_damage_reduction(except-source-base);stacking(max-only)|timed?:20:00(no-duration)|COST|city/siege-defense|placeholder:no-cross-base-aura-and-no-max-only-stack
202|巧緻収束|SG|T-SIEGE-SPD|army_siege_damage_percent(golem);army_stat_percent(speed,golem)|active?:10:00|COST+intelligence|siege/march|mixed:mechanisms-ready;lifecycle-unresolved
203|巨人の革命|SG|T-SIEGE-MULTIPLIER|army_stat_percent(attack,golem);army_stat_percent(speed,golem);army_siege_damage_multiplier(0.5)|active?:10:00|fixed|battle/march/siege|mixed:mechanisms-ready;lifecycle-unresolved
204|幸せの音色|SG|T-TIMED-ATK-SIEGE|army_stat_percent(attack,all);army_siege_damage_percent(golem)|timed?:48:00(no-duration)|COST|battle/siege|mixed:mechanisms-ready;duration-missing
205|幻想世界|CB|T-ATK-ALL|army_stat_percent(attack,all)|continuous:00:00|COST|battle|implemented:continuous
206|建設の紋章|BI|T-INFRA-BUILD|city_construction_speed_percent|timed?:10:00(no-duration)|COST+intelligence|city/construction|mixed:mechanism-ready;duration-missing
207|建設の聖刻|BI|T-INFRA-BUILD|city_construction_speed_percent|timed?:10:00(no-duration)|COST+intelligence|city/construction|mixed:mechanism-ready;duration-missing
208|強奪の紋章|IR|T-ATKSPD-REWARD|army_stat_percent(attack,all);army_stat_percent(speed,all);battle_reward_resource_percent|active?+event:30:00|COST|battle/march/reward|mixed:implemented+placeholder(no-battle-reward-hook)
209|応援の紋章|HT|T-INSTANT-TENSION|tension_change(waiting)|instant:48:00|fixed|activation/roster|placeholder:no-tension-and-no-waiting-roster-state
210|急援|RF|T-REINFORCE-SPD|army_stat_percent(speed,all);reinforcement_only_modifier|event:10:00|COST|reinforcement/march|placeholder:no-authoritative-reinforcement-identity
211|愛のぼかーん|BA|T-SAMEBASE-ATK|army_stat_percent(attack,all);same_base_aura|continuous:00:00|COST|city/battle|placeholder:no-source-same-base-aura
212|愛姫の精鋭|CB|T-ATKSPD-ADV|army_stat_percent(attack,advanced-unit);army_stat_percent(speed,advanced-unit);advanced_unit_scope|active?:10:00|COST|battle/march|placeholder:no-advanced-unit-entities
213|慧眼の紋章|CB|T-SPLIT-ATKSPD|army_stat_percent(attack,automata);army_stat_percent(attack,character);army_stat_percent(speed,all);character_automata_split_modifier|active?:10:00|COST+intelligence|battle/march|mixed:character-automata-split;lifecycle-unresolved
214|戦の化身|PP|T-ATK-POPULARITY|army_stat_percent(attack,all);territory_popularity_damage|active?:10:00|COST|battle/territory-popularity|mixed:implemented+placeholder(no-popularity-state)
215|戦の匠|TC|T-TRAIN-SPEED|city_training_speed_percent(pawn,golem)|active?:10:00|COST+intelligence|training|mixed:mechanism-ready;lifecycle-unresolved
216|戦の紋章|TC|T-TRAIN-SPEED|city_training_speed_percent(pawn,golem)|active?:10:00|COST|training|mixed:mechanism-ready;lifecycle-unresolved
217|戦乙女|CB|T-ATK-CHAR|army_stat_percent(attack,character);character_automata_scope|active?:10:00|COST|battle|mixed:source-character-scope-unsupported;lifecycle-unresolved
218|戦乱の行列|CB|T-ATK-MULSPD|army_stat_percent(attack,all);army_stat_multiplier(speed,all,0.5)|active?:10:00|COST;fixed|battle/march|mixed:mechanisms-ready;lifecycle-unresolved
219|戦姫の威光|PP|T-EVENT-POPULARITY|territory_popularity_damage(on-attack-success)|event:10:00|COST|post-battle/territory-popularity|placeholder:no-popularity-state-and-no-event-hook
220|戦姫の抑圧|PP|T-EVENT-POPULARITY|territory_popularity_damage(on-attack-success)|event:10:00|COST|post-battle/territory-popularity|placeholder:no-popularity-state-and-no-event-hook
221|戦果の代償|IR|T-EVENT-REWARD|battle_reward_resource_percent|event:auto/00:00|COST|post-battle/reward|placeholder:no-battle-reward-hook
222|戦果の心得|IR|T-EVENT-REWARD|battle_reward_resource_percent|event:auto/00:00|COST|post-battle/reward|placeholder:no-battle-reward-hook
223|戦果の才能|IR|T-EVENT-REWARD|battle_reward_resource_percent|event:auto/00:00|COST|post-battle/reward|placeholder:no-battle-reward-hook
224|戦果の真髄|IR|T-EVENT-REWARD|battle_reward_resource_percent|event:auto/00:00|COST|post-battle/reward|placeholder:no-battle-reward-hook
225|戦術の紋章|CB|T-ATKSPD-ALL|army_stat_percent(attack,all);army_stat_percent(speed,all)|active?:10:00|COST+intelligence|battle/march|mixed:mechanisms-ready;lifecycle-unresolved
226|戦陣の舞い|BA|T-SOURCEBASE-SPD|army_stat_percent(speed,all);source_base_dispatch_scope|timed?:72:00(no-duration)|fixed|march/source-base|placeholder:no-source-base-dispatch-scope;duration-missing
227|戦陣の舞踏|BA|T-SOURCEBASE-SPD|army_stat_percent(speed,all);source_base_dispatch_scope|timed?:72:00(no-duration)|fixed|march/source-base|placeholder:no-source-base-dispatch-scope;duration-missing
228|戦陣の踊り|BA|T-SOURCEBASE-SPD|army_stat_percent(speed,all);source_base_dispatch_scope|timed?:72:00(no-duration)|fixed|march/source-base|placeholder:no-source-base-dispatch-scope;duration-missing
229|拠点増強|BI|T-INSTANT-REPAIR|repair_assigned_city|instant:48:00|COST|activation/city|adapted:assigned-city
230|文殊の知恵|CR|T-SAMEBASE-INT-ATK|army_stat_percent(attack,all);same_base_intelligence_scaling;secondary_stat_scaling(intelligence)|active?:60:00|fixed;intelligence|battle/city|mixed:implemented+placeholder(missing-scaling-coefficient-and-same-base-snapshot)
231|星の士気|CR|T-ELEMENT-ATK|army_element_stat_percent(attack,bright)|continuous:00:00|fixed|battle/element-roster|adapted:source-star-to-bright
232|星の守護|CR|T-ELEMENT-DEF|army_element_stat_percent(defense,bright)|active?:48:00|fixed|battle/element-roster/defense|mixed:element-map-ready;lifecycle-unresolved
233|星の攻撃|CR|T-ELEMENT-ATK|army_element_stat_percent(attack,bright)|active?:10:00|fixed|battle/element-roster|mixed:element-map-ready;lifecycle-unresolved
234|星の神撃|CR|T-ELEMENT-ATK|army_element_stat_percent(attack,bright)|active?:10:00|fixed|battle/element-roster|mixed:element-map-ready;lifecycle-unresolved
235|星の衝撃|CR|T-ELEMENT-ATK|army_element_stat_percent(attack,bright)|active?:10:00|fixed|battle/element-roster|mixed:element-map-ready;lifecycle-unresolved
236|星の覇気|CR|T-ELEMENT-ATK|army_element_stat_percent(attack,bright)|continuous:00:00|fixed|battle/element-roster|adapted:source-star-to-bright
237|星の豪撃|CR|T-ELEMENT-ATK|army_element_stat_percent(attack,bright)|active?:10:00|fixed|battle/element-roster|mixed:element-map-ready;lifecycle-unresolved
238|星の闘気|CR|T-ELEMENT-ATK|army_element_stat_percent(attack,bright)|continuous:00:00|fixed|battle/element-roster|adapted:source-star-to-bright
239|星雫の咲姫|CB|T-SPLIT-ATKSPD|army_stat_percent(attack,automata);army_stat_percent(attack,character);army_stat_percent(speed,all);character_automata_split_modifier|active?:40:00|COST|battle/march|mixed:character-automata-split;lifecycle-unresolved
240|暁光流転|SG|T-SIEGE-RETURN|army_stat_percent(attack,character);character_automata_scope;army_siege_damage_percent(golem);army_stat_percent(speed,all);army_return_speed_percent|active?:10:00|COST|battle/siege/march/return|mixed:source-character-scope-unsupported;lifecycle-unresolved
241|曙光散華|SG|T-SIEGE-RETURN|army_stat_percent(attack,character);character_automata_scope;army_siege_damage_percent(golem);army_stat_percent(speed,all);army_return_speed_percent|active?:10:00|COST|battle/siege/march/return|mixed:source-character-scope-unsupported;lifecycle-unresolved
242|月の士気|CR|T-ELEMENT-ATK|army_element_stat_percent(attack,night)|continuous:00:00|fixed|battle/element-roster|adapted:source-moon-to-night
243|月の守護|CR|T-ELEMENT-DEF|army_element_stat_percent(defense,night)|active?:48:00|fixed|battle/element-roster/defense|mixed:element-map-ready;lifecycle-unresolved
244|月の攻撃|CR|T-ELEMENT-ATK|army_element_stat_percent(attack,night)|active?:10:00|fixed|battle/element-roster|mixed:element-map-ready;lifecycle-unresolved
245|月の神撃|CR|T-ELEMENT-ATK|army_element_stat_percent(attack,night)|active?:10:00|fixed|battle/element-roster|mixed:element-map-ready;lifecycle-unresolved
246|月の衝撃|CR|T-ELEMENT-ATK|army_element_stat_percent(attack,night)|active?:10:00|fixed|battle/element-roster|mixed:element-map-ready;lifecycle-unresolved
247|月の覇気|CR|T-ELEMENT-ATK|army_element_stat_percent(attack,night)|continuous:00:00|fixed|battle/element-roster|adapted:source-moon-to-night
248|月の豪撃|CR|T-ELEMENT-ATK|army_element_stat_percent(attack,night)|active?:10:00|fixed|battle/element-roster|mixed:element-map-ready;lifecycle-unresolved
249|月の闘気|CR|T-ELEMENT-ATK|army_element_stat_percent(attack,night)|continuous:00:00|fixed|battle/element-roster|adapted:source-moon-to-night
250|月詠ノ花嫁|CB|T-ATK-CHAR|army_stat_percent(attack,character);character_automata_scope|active?:60:00|COST|battle|mixed:source-character-scope-unsupported;lifecycle-unresolved
251|朱の創造主|CB|T-ATK-UNIT|army_stat_percent(attack,rook)|active?:60:00|COST|battle|mixed:mechanism-ready;lifecycle-unresolved
252|朱眼の睥睨|CR|T-ATK-NPC-UNIT|army_stat_percent(attack,rook);army_stat_percent(attack,rook,condition=target_tag:npc)|active?:30:00|COST|battle/NPC|mixed:mechanisms-ready;lifecycle-unresolved
253|杖の丘|CB|T-ATK-UNIT|army_stat_percent(attack,bishop)|active?:60:00|COST|battle|mixed:mechanism-ready;lifecycle-unresolved
254|桜色の軌跡|CB|T-SPLIT-ATK|army_stat_percent(attack,automata);army_stat_percent(attack,character);character_automata_split_modifier|active?:60:00|COST|battle|mixed:character-automata-split;lifecycle-unresolved
255|極♡ビンタ|CB|T-ATKSPD-CHAR|army_stat_percent(attack,character);army_stat_percent(speed,character);character_automata_scope|active?:60:00|COST|battle/march|mixed:source-character-scope-unsupported;lifecycle-unresolved
256|極光集束|SG|T-SIEGE-SPD|army_siege_damage_percent(golem);army_stat_percent(speed,golem)|active?:10:00|COST+intelligence|siege/march|mixed:mechanisms-ready;lifecycle-unresolved
257|槍の丘|CB|T-ATK-UNIT|army_stat_percent(attack,knight)|active?:60:00|COST|battle|mixed:mechanism-ready;lifecycle-unresolved
258|武運の舞い|BA|T-SOURCEBASE-SPD|army_stat_percent(speed,character);character_automata_scope;source_base_dispatch_scope|timed?:72:00(no-duration)|fixed|march/source-base|placeholder:no-character-scope-and-no-source-base-dispatch-scope;duration-missing
259|武運の舞踏|BA|T-SOURCEBASE-SPD|army_stat_percent(speed,character);character_automata_scope;source_base_dispatch_scope|timed?:72:00(no-duration)|fixed|march/source-base|placeholder:no-character-scope-and-no-source-base-dispatch-scope;duration-missing
260|武運の踊り|BA|T-SOURCEBASE-SPD|army_stat_percent(speed,character);character_automata_scope;source_base_dispatch_scope|timed?:72:00(no-duration)|fixed|march/source-base|placeholder:no-character-scope-and-no-source-base-dispatch-scope;duration-missing
261|歩兵の剛守|DF|T-DEF-MULTIUNIT|army_stat_percent(defense,scout);army_stat_percent(defense,pawn)|active?:10:00|COST|battle/defense|mixed:mechanisms-ready;lifecycle-unresolved
262|歩兵の加速|CB|T-SPD-UNIT|army_stat_percent(speed,pawn)|active?:10:00|COST|march|mixed:mechanism-ready;lifecycle-unresolved
263|歩兵の堅守|DF|T-DEF-MULTIUNIT|army_stat_percent(defense,scout);army_stat_percent(defense,pawn)|active?:10:00|COST|battle/defense|mixed:mechanisms-ready;lifecycle-unresolved
264|歩兵の境地|CB|T-ATKSPD-UNIT|army_stat_percent(attack,pawn);army_stat_percent(speed,pawn)|continuous:00:00|COST|battle/march|implemented:continuous
265|歩兵の奥義|CB|T-ATKSPD-UNIT|army_stat_percent(attack,pawn);army_stat_percent(speed,pawn)|active?:10:00|COST|battle/march|mixed:mechanisms-ready;lifecycle-unresolved
266|歩兵の守備|DF|T-DEF-MULTIUNIT|army_stat_percent(defense,scout);army_stat_percent(defense,pawn)|active?:10:00|COST|battle/defense|mixed:mechanisms-ready;lifecycle-unresolved
267|歩兵の強襲|CB|T-ATKSPD-UNIT|army_stat_percent(attack,pawn);army_stat_percent(speed,pawn)|active?:10:00|COST|battle/march|mixed:mechanisms-ready;lifecycle-unresolved
268|歩兵の攻撃|CB|T-ATK-UNIT|army_stat_percent(attack,pawn)|active?:10:00|COST|battle|mixed:mechanism-ready;lifecycle-unresolved
269|歩兵の神器|CB|T-ATK-UNIT|army_stat_percent(attack,pawn)|continuous:00:00|COST|battle|implemented:continuous
270|歩兵の神撃|CB|T-ATK-UNIT|army_stat_percent(attack,pawn)|active?:10:00|COST|battle|mixed:mechanism-ready;lifecycle-unresolved
271|歩兵の突攻|CB|T-ATKSPD-UNIT|army_stat_percent(attack,pawn);army_stat_percent(speed,pawn)|active?:10:00|COST|battle/march|mixed:mechanisms-ready;lifecycle-unresolved
272|歩兵の衝撃|CB|T-ATK-UNIT|army_stat_percent(attack,pawn)|active?:10:00|COST|battle|mixed:mechanism-ready;lifecycle-unresolved
273|歩兵の豪撃|CB|T-ATK-UNIT|army_stat_percent(attack,pawn)|active?:10:00|COST|battle|mixed:mechanism-ready;lifecycle-unresolved
274|歩兵の速攻|CB|T-SPD-UNIT|army_stat_percent(speed,pawn)|active?:10:00|COST|march|mixed:mechanism-ready;lifecycle-unresolved
275|歩兵の進軍|CB|T-ATKSPD-UNIT|army_stat_percent(attack,pawn);army_stat_percent(speed,pawn)|active?:10:00|COST|battle/march|mixed:mechanisms-ready;lifecycle-unresolved
276|歩兵の闘気|CB|T-ATK-UNIT|army_stat_percent(attack,pawn)|continuous:00:00|COST|battle|implemented:continuous
277|歩兵の防衛陣|DF|T-DEF-MULTIUNIT|army_stat_percent(defense,scout);army_stat_percent(defense,pawn)|active?:10:00|COST|battle/defense|mixed:mechanisms-ready;lifecycle-unresolved
278|歩兵の頂き|CB|T-ATKSPD-UNIT|army_stat_percent(attack,pawn);army_stat_percent(speed,pawn)|continuous:00:00|COST|battle/march|implemented:continuous
279|歩兵の高速|CB|T-SPD-UNIT|army_stat_percent(speed,pawn)|active?:10:00|COST|march|mixed:mechanism-ready;lifecycle-unresolved
280|歩兵募集|TC|T-TRAIN-SPEED|city_training_speed_percent(pawn)|active?:10:00|COST|training|mixed:mechanism-ready;lifecycle-unresolved
281|歩兵急募|TC|T-TRAIN-SPEED|city_training_speed_percent(pawn)|active?:10:00|COST|training|mixed:mechanism-ready;lifecycle-unresolved
282|気高く苛烈に|CB|T-ATK-CHAR|army_stat_percent(attack,character);character_automata_scope|active?:60:00|COST|battle|mixed:source-character-scope-unsupported;lifecycle-unresolved
283|水土の息吹|RC|T-COLLECT|resource_collection_percent(water,earth)|active?:10:00|COST|resource-map|placeholder:no-resource-node-assignment
284|水土の恵み|RC|T-COLLECT|resource_collection_percent(water,earth)|active?:10:00|COST|resource-map|placeholder:no-resource-node-assignment
285|水晶の結集|IR|T-ATK-REWARD|army_stat_percent(attack,all);battle_reward_resource_percent|active?+event:10:00|COST|battle/reward|mixed:implemented+placeholder(no-battle-reward-hook)
286|水精の大豊穣|RC|T-COLLECT|resource_collection_percent(water)|active?:10:00|COST|resource-map|placeholder:no-resource-node-assignment
287|水精の息吹|RC|T-COLLECT|resource_collection_percent(water)|active?:10:00|COST|resource-map|placeholder:no-resource-node-assignment
288|水精の恵み|RC|T-COLLECT|resource_collection_percent(water)|active?:10:00|COST|resource-map|placeholder:no-resource-node-assignment
289|水精の豊穣|RC|T-COLLECT|resource_collection_percent(water)|active?:10:00|COST|resource-map|placeholder:no-resource-node-assignment
290|水風の息吹|RC|T-COLLECT|resource_collection_percent(water,wind)|active?:10:00|COST|resource-map|placeholder:no-resource-node-assignment
291|水風の恵み|RC|T-COLLECT|resource_collection_percent(water,wind)|active?:10:00|COST|resource-map|placeholder:no-resource-node-assignment
292|永久ノ不敗|BA|T-SELF-BASE-DEF|army_stat_percent(defense,character);character_automata_scope;same_base_aura|continuous:00:00|COST|city/defense|placeholder:no-character-scope-and-no-source-same-base-aura
293|流麗の紋章|CB|T-SPLIT-ATKSPD|army_stat_percent(attack,automata);army_stat_percent(attack,character);army_stat_percent(speed,all);character_automata_split_modifier|active?:20:00|COST+intelligence|battle/march|mixed:character-automata-split;lifecycle-unresolved
294|浄化神技|CB|T-ATKSPD-UNIT|army_stat_percent(attack,bishop);army_stat_percent(speed,bishop)|active?:10:00|COST|battle/march|mixed:mechanisms-ready;lifecycle-unresolved
295|深黒魔法少女|SG|T-SIEGE-SPD-FLAT|army_siege_damage_percent(golem);army_stat_percent(speed,golem);army_siege_damage_flat|active?:10:00|COST+intelligence;fixed|siege/march|mixed:mechanisms-ready;lifecycle-unresolved
296|火土の息吹|RC|T-COLLECT|resource_collection_percent(fire,earth)|active?:10:00|COST|resource-map|placeholder:no-resource-node-assignment
297|火土の恵み|RC|T-COLLECT|resource_collection_percent(fire,earth)|active?:10:00|COST|resource-map|placeholder:no-resource-node-assignment
298|火水の息吹|RC|T-COLLECT|resource_collection_percent(fire,water)|active?:10:00|COST|resource-map|placeholder:no-resource-node-assignment
299|火水の恵み|RC|T-COLLECT|resource_collection_percent(fire,water)|active?:10:00|COST|resource-map|placeholder:no-resource-node-assignment
300|火精の大豊穣|RC|T-COLLECT|resource_collection_percent(fire)|active?:10:00|COST|resource-map|placeholder:no-resource-node-assignment
301|火精の息吹|RC|T-COLLECT|resource_collection_percent(fire)|active?:10:00|COST|resource-map|placeholder:no-resource-node-assignment
302|火精の恵み|RC|T-COLLECT|resource_collection_percent(fire)|active?:10:00|COST|resource-map|placeholder:no-resource-node-assignment
303|火精の豊穣|RC|T-COLLECT|resource_collection_percent(fire)|active?:10:00|COST|resource-map|placeholder:no-resource-node-assignment
304|煌迅収束|SG|T-SIEGE-SPD|army_siege_damage_percent(golem);army_stat_percent(speed,golem)|active?:10:00|COST+intelligence|siege/march|mixed:mechanisms-ready;lifecycle-unresolved
305|牽制の一矢|SK|T-SKIRMISH-DAMAGE|army_stat_percent(attack,all);army_stat_multiplier(speed,all,0.5);skirmish_only_modifier;defender_general_damage|event:20:00|COST;fixed|skirmish/battle|placeholder:no-skirmish-and-no-defender-damage-event
306|牽制の奥義|SK|T-SKIRMISH-ATKSPD|army_stat_percent(attack,all);army_stat_percent(speed,all);skirmish_only_modifier|event:10:00|COST|skirmish/battle/march|placeholder:no-skirmish-event
307|牽制の突攻|SK|T-SKIRMISH-ATKSPD|army_stat_percent(attack,all);army_stat_percent(speed,all);skirmish_only_modifier|event:10:00|COST|skirmish/battle/march|placeholder:no-skirmish-event
308|牽制の速攻|SK|T-SKIRMISH-ATKSPD|army_stat_percent(attack,all);army_stat_percent(speed,all);skirmish_only_modifier|event:10:00|COST|skirmish/battle/march|placeholder:no-skirmish-event
309|牽制の進軍|SK|T-SKIRMISH-ATKSPD|army_stat_percent(attack,all);army_stat_percent(speed,all);skirmish_only_modifier|event:10:00|COST|skirmish/battle/march|placeholder:no-skirmish-event
310|牽制突破|SK|T-SKIRMISH-DAMAGE|skirmish_only_modifier;defender_general_damage|event:10:00|COST|skirmish/post-battle|placeholder:no-skirmish-and-no-defender-damage-event
311|獅子心姫|CB|T-ATKSPD-ADV|army_stat_percent(attack,advanced-unit);army_stat_percent(speed,advanced-unit);advanced_unit_scope|active?:10:00|COST|battle/march|placeholder:no-advanced-unit-entities
312|甲冑の聖刻|BA|T-SELF-BASE-DEF|army_stat_percent(defense,character);character_automata_scope;same_base_aura|continuous:00:00|COST|city/defense|placeholder:no-character-scope-and-no-source-same-base-aura
313|略奪の紋章|IR|T-ATKSPD-REWARD|army_stat_percent(attack,all);army_stat_percent(speed,all);battle_reward_resource_percent|active?+event:30:00|COST|battle/march/reward|mixed:implemented+placeholder(no-battle-reward-hook)
314|疾風の紋章|CB|T-SPD-ALL|army_stat_percent(speed,all)|active?:10:00|COST|march|mixed:mechanism-ready;lifecycle-unresolved
315|疾風の聖刻|CB|T-SPD-CHAR|army_stat_percent(speed,character);character_automata_scope|active?:10:00|COST|march|mixed:source-character-scope-unsupported;lifecycle-unresolved
316|疾駆の心得|CB|T-SPD-ALL|army_stat_percent(speed,all)|continuous:00:00|COST|march|implemented:continuous
317|疾駆の才能|CB|T-SPD-ALL|army_stat_percent(speed,all)|continuous:00:00|COST|march|implemented:continuous
318|疾駆の真髄|CB|T-SPD-ALL|army_stat_percent(speed,all)|continuous:00:00|COST|march|implemented:continuous
319|疾駆の翼|CB|T-SPD-ALL|army_stat_percent(speed,all)|continuous:00:00|COST|march|implemented:continuous
320|癒しの指先|HT|T-INSTANT-WAIT-HEAL-TENSION|heal_generals(waiting);waiting_roster_heal;tension_change(waiting)|instant:72:00|fixed|activation/roster|placeholder:no-tension-and-no-source-waiting-roster-state;source-label-anomaly
321|発展の聖刻|BI|T-INFRA-DEVELOP|territory_development_speed|timed?:20:00(no-duration)|COST|territory/development|placeholder:no-territory-level-timer
322|白き羽の乙女|CB|T-ATKSPD-CHAR|army_stat_percent(attack,character);army_stat_percent(speed,character);character_automata_scope|active?:60:00|COST|battle/march|mixed:source-character-scope-unsupported;lifecycle-unresolved
323|白亜の城壁|DF|T-DEF-ALL|army_stat_percent(defense,all)|active?:36:00|COST+intelligence|battle/defense|mixed:mechanism-ready;lifecycle-unresolved
324|百姫夜行|CB|T-ATK-MULSPD|army_stat_percent(attack,all);army_stat_multiplier(speed,all,0.5)|active?:60:00|COST;fixed|battle/march|mixed:mechanisms-ready;lifecycle-unresolved
325|皆でばびゅーん|BA|T-SAMEBASE-SPD|army_stat_percent(speed,all);same_base_aura|continuous:00:00|COST|city/march|placeholder:no-source-same-base-aura
326|皆でひゅーん!|BA|T-SAMEBASE-SPD|army_stat_percent(speed,all);same_base_aura|continuous:00:00|COST|city/march|placeholder:no-source-same-base-aura
327|皆でびゅーん!|BA|T-SAMEBASE-SPD|army_stat_percent(speed,all);same_base_aura|continuous:00:00|COST|city/march|placeholder:no-source-same-base-aura
328|皇翼の輝翔|CR|T-DIST-SCALE|army_stat_percent(attack,all);army_stat_percent(speed,all);distance_scaling;source-placeholder-values|active?:50:00|COST|battle/march/distance|mixed:implemented+placeholder(distance-value-scaling);source-anomaly
329|瞬神一閃|SK|T-SKIRMISH-ATKSPD|army_stat_percent(attack,all);army_stat_percent(speed,all);skirmish_only_modifier|event:20:00|COST|skirmish/battle/march|placeholder:no-skirmish-event
330|瞬秒の嚆矢|CR|T-SECONDARY-SPD|army_stat_percent(attack,all);army_stat_percent(speed,all);secondary_stat_scaling(speed)|active?:10:00|fixed;source-stat|battle/march|mixed:implemented+placeholder(missing-secondary-scaling-coefficient)
331|瞬霞終刀|SK|T-SKIRMISH-ATKSPD|army_stat_percent(attack,all);army_stat_percent(speed,all);skirmish_only_modifier|event:20:00|COST|skirmish/battle/march|placeholder:no-skirmish-event
332|知恵の紋章|CB|T-SPLIT-ATKSPD|army_stat_percent(attack,automata);army_stat_percent(attack,character);army_stat_percent(speed,all);character_automata_split_modifier|active?:10:00|COST+intelligence|battle/march|mixed:character-automata-split;lifecycle-unresolved
333|破壊と寵愛|PP|T-ATKSPD-POPULARITY-RESTORE|army_stat_percent(attack,all);army_stat_percent(speed,all);territory_popularity_restore(on-conquest)|active?+event:30:00|COST|battle/march/post-conquest|mixed:implemented+placeholder(no-popularity-state-and-no-conquest-event)
334|破壊の加速|SG|T-SPD-MULTIUNIT|army_stat_percent(speed,pawn);army_stat_percent(speed,golem)|active?:10:00|COST|march|mixed:mechanisms-ready;lifecycle-unresolved
335|破壊の喝采|SG|T-TIMED-ATK-SIEGE|army_stat_percent(attack,golem);army_siege_damage_percent(golem)|timed?:48:00(no-duration)|COST|battle/siege|mixed:mechanisms-ready;duration-missing
336|破壊の声援|SG|T-TIMED-ATK-SIEGE|army_stat_percent(attack,golem);army_siege_damage_percent(golem)|timed?:48:00(no-duration)|COST|battle/siege|mixed:mechanisms-ready;duration-missing
337|破壊の守備|SG|T-DEF-MULTIUNIT|army_stat_percent(defense,pawn);army_stat_percent(defense,golem)|active?:10:00|COST|battle/defense|mixed:mechanisms-ready;lifecycle-unresolved
338|破壊の攻撃|SG|T-ATK-SIEGE|army_stat_percent(attack,pawn);army_siege_damage_percent(golem)|active?:10:00|COST|battle/siege|mixed:mechanisms-ready;lifecycle-unresolved
339|破壊の神撃|SG|T-ATK-SIEGE|army_stat_percent(attack,pawn);army_siege_damage_percent(golem)|active?:10:00|COST|battle/siege|mixed:mechanisms-ready;lifecycle-unresolved
340|破壊の衝撃|SG|T-ATK-SIEGE|army_stat_percent(attack,pawn);army_siege_damage_percent(golem)|active?:10:00|COST|battle/siege|mixed:mechanisms-ready;lifecycle-unresolved
341|破壊の豪撃|SG|T-ATK-SIEGE|army_stat_percent(attack,pawn);army_siege_damage_percent(golem)|active?:10:00|COST|battle/siege|mixed:mechanisms-ready;lifecycle-unresolved
342|破壊の速攻|SG|T-SPD-MULTIUNIT|army_stat_percent(speed,pawn);army_stat_percent(speed,golem)|active?:10:00|COST|march|mixed:mechanisms-ready;lifecycle-unresolved
343|破壊の高速|SG|T-SPD-MULTIUNIT|army_stat_percent(speed,pawn);army_stat_percent(speed,golem)|active?:10:00|COST|march|mixed:mechanisms-ready;lifecycle-unresolved
344|破壊募集|TC|T-TRAIN-SPEED|city_training_speed_percent(golem)|active?:10:00|COST|training|mixed:mechanism-ready;lifecycle-unresolved
345|破壊急募|TC|T-TRAIN-SPEED|city_training_speed_percent(golem)|active?:10:00|COST|training|mixed:mechanism-ready;lifecycle-unresolved
346|破槌の天翼|SG|T-SIEGE-FLAT|army_stat_percent(attack,golem);army_stat_percent(speed,golem);army_siege_damage_flat|active?:10:00|fixed|battle/march/siege|mixed:mechanisms-ready;lifecycle-unresolved
347|破滅の天雷|CB|T-ATK-CHAR|army_stat_percent(attack,character);character_automata_scope|active?:30:00|COST|battle|mixed:source-character-scope-unsupported;lifecycle-unresolved
348|祈りの紋章|CB|T-SPLIT-ATK|army_stat_percent(attack,automata);character_automata_split_modifier|active?:20:00|fixed|battle|mixed:automata-only-scope;lifecycle-unresolved
349|祝賀の響音|CB|T-SPLIT-ATK|army_stat_percent(attack,automata);army_stat_percent(attack,character);character_automata_split_modifier|active?:20:00|COST|battle|mixed:character-automata-split;lifecycle-unresolved
350|神姫飛翔|CB|T-SPLIT-ATKSPD|army_stat_percent(attack,automata);army_stat_percent(attack,character);army_stat_percent(speed,all);character_automata_split_modifier|active?:30:00|COST|battle/march|mixed:character-automata-split;lifecycle-unresolved
351|神意の使者|BA|T-TIMED-ATKSPD|army_stat_percent(attack,all);army_stat_percent(speed,all)|timed?:60:00(no-duration)|COST|battle/march|mixed:mechanisms-ready;duration-missing
352|神盾の姫君|DF|T-DEF-ALL|army_stat_percent(defense,all)|active?:36:00|COST|battle/defense|mixed:mechanism-ready;lifecycle-unresolved
353|神聖乙女|CB|T-ATKSPD-ALL|army_stat_percent(attack,all);army_stat_percent(speed,all)|active?:40:00|COST|battle/march|mixed:mechanisms-ready;lifecycle-unresolved
354|神薙ぎ|CB|T-ATKCHAR-SPDALL|army_stat_percent(attack,character);character_automata_scope;army_stat_percent(speed,all)|active?:50:00|COST|battle/march|mixed:source-character-scope-unsupported;lifecycle-unresolved
355|神話の勇者|CB|T-ATK-CHAR|army_stat_percent(attack,character);character_automata_scope|continuous:00:00|COST|battle|mixed:source-character-scope-unsupported
356|空に描く星印|CB|T-SPLIT-ATKSPD|army_stat_percent(attack,automata);army_stat_percent(attack,character);army_stat_percent(speed,all);character_automata_split_modifier|active?:60:00|COST|battle/march|mixed:character-automata-split;lifecycle-unresolved
357|精緻の紋章|BA|T-SCOUT-DUEL|army_stat_percent(attack,scout);army_stat_percent(defense,scout);scout_vs_scout_scope;advanced_unit_scope(high-scout)|timed?:48:00(no-duration)|COST|battle/scout-duel|placeholder:no-scout-duel-context-and-no-advanced-unit;duration-missing
358|精緻収束|SG|T-SIEGE-SPD|army_siege_damage_percent(golem);army_stat_percent(speed,golem)|active?:10:00|COST+intelligence|siege/march|mixed:mechanisms-ready;lifecycle-unresolved
359|精霊の叡智|RC|T-COLLECT|resource_collection_percent(wind,fire,water)|active?:20:00|COST+intelligence|resource-map|placeholder:no-resource-node-assignment
360|精霊の息吹|RC|T-COLLECT|resource_collection_percent(wind,fire,water)|active?:10:00|COST|resource-map|placeholder:no-resource-node-assignment
361|精霊の恵み|RC|T-COLLECT|resource_collection_percent(wind,fire,water)|active?:10:00|COST|resource-map|placeholder:no-resource-node-assignment
362|精霊の歌|RC|T-COLLECT-BUILD|resource_collection_percent(wind,fire,water);city_construction_speed_percent|active?:20:00|COST|resource-map/city-construction|mixed:implemented+placeholder(no-resource-node-assignment);lifecycle-unresolved
363|精霊の舞|RC|T-COLLECT-TRAIN|resource_collection_percent(wind,fire,water,earth);city_training_speed_percent(all)|active?:72:00|fixed|resource-map/training|mixed:implemented+placeholder(no-resource-node-assignment);lifecycle-unresolved;source-label-anomaly
364|約束の紋章|CB|T-ATKSPD-ALL|army_stat_percent(attack,all);army_stat_percent(speed,all)|active?:60:00|COST+intelligence|battle/march|mixed:mechanisms-ready;lifecycle-unresolved
365|紅の果て|CB|T-ATKSPD-UNIT|army_stat_percent(attack,rook);army_stat_percent(speed,rook)|active?:30:00|COST|battle/march|mixed:mechanisms-ready;lifecycle-unresolved
366|紅蓮雪月花|CB|T-ATKSPD-UNIT|army_stat_percent(attack,rook);army_stat_percent(speed,rook)|active?:30:00|COST|battle/march|mixed:mechanisms-ready;lifecycle-unresolved
367|純真の紋章|CB|T-ATKSPD-ALL|army_stat_percent(attack,all);army_stat_percent(speed,all)|active?:48:00|COST+intelligence|battle/march|mixed:mechanisms-ready;lifecycle-unresolved
368|絶剣無双|CB|T-ATKSPD-UNIT|army_stat_percent(attack,rook);army_stat_percent(speed,rook)|active?:10:00|COST|battle/march|mixed:mechanisms-ready;lifecycle-unresolved
369|絶対負けない!|BA|T-SELF-BASE-DEF|army_stat_percent(defense,character);character_automata_scope;same_base_aura|continuous:00:00|COST|city/defense|placeholder:no-character-scope-and-no-source-same-base-aura
370|美姫の我儘|IR|T-SOURCEBASE-REWARD|battle_reward_resource_percent;source_base_dispatch_scope|timed?+event:48:00(no-duration)|COST|source-base/post-battle/reward|placeholder:no-source-base-scope-and-no-battle-reward-hook;duration-missing
371|美少女賛歌|CB|T-ATKSPD-ALL|army_stat_percent(attack,all);army_stat_percent(speed,all)|active?:60:00|COST|battle/march|mixed:mechanisms-ready;lifecycle-unresolved
372|翔援|RF|T-REINFORCE-SPD|army_stat_percent(speed,all);reinforcement_only_modifier|event:10:00|COST|reinforcement/march|placeholder:no-authoritative-reinforcement-identity
373|翠の軌跡|CB|T-ATKSPD-UNIT|army_stat_percent(attack,bishop);army_stat_percent(speed,bishop)|active?:30:00|COST|battle/march|mixed:mechanisms-ready;lifecycle-unresolved
374|翠ノ光鎚|CB|T-ATKSPD-UNIT|army_stat_percent(attack,bishop);army_stat_percent(speed,bishop)|active?:30:00|COST|battle/march|mixed:mechanisms-ready;lifecycle-unresolved
375|翠眼の睥睨|CR|T-ATK-NPC-UNIT|army_stat_percent(attack,bishop);army_stat_percent(attack,bishop,condition=target_tag:npc)|active?:30:00|COST|battle/NPC|mixed:mechanisms-ready;lifecycle-unresolved
376|聖乙女領域|CR|T-ATK-DIST|army_stat_percent(attack,character);character_automata_scope;condition(distance,lte,10)|active?:60:00|COST|battle/short-range|mixed:source-character-scope-unsupported;lifecycle-unresolved
377|聖女の断罪|CB|T-ATK-CHAR|army_stat_percent(attack,character);character_automata_scope|active?:40:00|COST|battle|mixed:source-character-scope-unsupported;lifecycle-unresolved
378|聖姫の神撃|CB|T-SPLIT-ATKSPD|army_stat_percent(attack,automata);army_stat_percent(attack,character);army_stat_percent(speed,all);character_automata_split_modifier|active?:10:00|COST|battle/march|mixed:character-automata-split;lifecycle-unresolved
379|聖白の天使|CR|T-ATK-DIST|army_stat_percent(attack,character);character_automata_scope;condition(distance,lte,10)|active?:30:00|COST|battle/short-range|mixed:source-character-scope-unsupported;lifecycle-unresolved
380|聖白衣の天使|CR|T-ATK-DIST|army_stat_percent(attack,character);character_automata_scope;condition(distance,lte,10)|active?:60:00|COST|battle/short-range|mixed:source-character-scope-unsupported;lifecycle-unresolved
381|至宝の真眼|TR|T-TREASURE-FIND-SPD|army_stat_percent(speed,all);treasure_find_chance|continuous:00:00|COST;fixed|march/treasure|mixed:implemented+placeholder(no-treasure-state)
382|至宝の眼力|TR|T-TREASURE-FIND-SPD|army_stat_percent(speed,all);treasure_find_chance|continuous:00:00|COST;fixed|march/treasure|mixed:implemented+placeholder(no-treasure-state)
383|至宝の鋭眼|TR|T-TREASURE-FIND-SPD|army_stat_percent(speed,all);treasure_find_chance|continuous:00:00|COST;fixed|march/treasure|mixed:implemented+placeholder(no-treasure-state)
384|蒼の境界|CB|T-ATKSPD-UNIT|army_stat_percent(attack,knight);army_stat_percent(speed,knight)|active?:30:00|COST|battle/march|mixed:mechanisms-ready;lifecycle-unresolved
385|蒼天瀑布|CB|T-ATKSPD-UNIT|army_stat_percent(attack,knight);army_stat_percent(speed,knight)|active?:30:00|COST|battle/march|mixed:mechanisms-ready;lifecycle-unresolved
386|蒼眼の睥睨|CR|T-ATK-NPC-UNIT|army_stat_percent(attack,knight);army_stat_percent(attack,knight,condition=target_tag:npc)|active?:30:00|COST|battle/NPC|mixed:mechanisms-ready;lifecycle-unresolved
387|虹色のソラ|CB|T-SPLIT-ATKSPD|army_stat_percent(attack,automata);army_stat_percent(attack,character);army_stat_percent(speed,all);character_automata_split_modifier|active?:60:00|COST|battle/march|mixed:character-automata-split;lifecycle-unresolved
388|衛士の誓約|DF|T-SECONDARY-DEF|army_stat_percent(attack,all);army_stat_percent(speed,all);secondary_stat_scaling(defense)|active?:10:00|fixed;source-stat|battle/march|mixed:implemented+placeholder(missing-secondary-scaling-coefficient)
389|詠唱短縮|HT|T-INSTANT-CD|reduce_skill_cooldowns(unassigned-owned);waiting_roster_scope|instant:72:00|fixed|activation/roster|adapted:source-waiting-to-unassigned-owned
390|諜報の調べ|BA|T-SOURCEBASE-SPD-ADV|army_stat_percent(speed,scout);source_base_dispatch_scope;advanced_unit_scope(high-scout)|timed?:10:00(no-duration)|COST|march/scouting/source-base|placeholder:no-source-base-scope-and-no-advanced-unit;duration-missing
391|謳歌|PP|T-ATK-POPULARITY|army_stat_percent(attack,all);territory_popularity_damage|active?:10:00|COST|battle/territory-popularity|mixed:implemented+placeholder(no-popularity-state)
392|護りの紋章|DF|T-DEF-ALL|army_stat_percent(defense,all)|active?:10:00|COST|battle/defense|mixed:mechanism-ready;lifecycle-unresolved
393|赤皇の特権|HT|T-ATK-HPCOST|army_stat_percent(attack,character);character_automata_scope;hp_cost_on_attack|active?+event:40:00|COST;fixed|battle/dispatch|mixed:source-character-scope+placeholder(no-audited-hp-cost)
394|赦しの紋章|CB|T-ATKSPD-ALL|army_stat_percent(attack,all);army_stat_percent(speed,all)|active?:60:00|COST+intelligence|battle/march|mixed:mechanisms-ready;lifecycle-unresolved
395|超☆ビンタ|CB|T-ATKSPD-CHAR|army_stat_percent(attack,character);army_stat_percent(speed,character);character_automata_scope|active?:60:00|COST|battle/march|mixed:source-character-scope-unsupported;lifecycle-unresolved
396|蹂躙舞踏|CB|T-ATKSPD-UNIT|army_stat_percent(attack,knight);army_stat_percent(speed,knight)|active?:10:00|COST|battle/march|mixed:mechanisms-ready;lifecycle-unresolved
397|輝く愛|CB|T-ATKSPD-ADV|army_stat_percent(attack,advanced-unit);army_stat_percent(speed,advanced-unit);advanced_unit_scope|active?:30:00|COST|battle/march|placeholder:no-advanced-unit-entities
398|追憶と焦土|CB|T-ATK-CHAR|army_stat_percent(attack,character);character_automata_scope|active?:60:00|COST|battle|mixed:source-character-scope-unsupported;lifecycle-unresolved
399|金剛の聖刻|BA|T-SELF-BASE-DEF|army_stat_percent(defense,character);character_automata_scope;same_base_aura|continuous:00:00|COST|city/defense|placeholder:no-character-scope-and-no-source-same-base-aura
400|鉄拳ろけっと!|CR|T-ATK-DIST|army_stat_percent(attack,character);character_automata_scope;condition(distance,lte,10)|active?:60:00|COST|battle/short-range|mixed:source-character-scope-unsupported;lifecycle-unresolved
401|銀河集束|SG|T-SIEGE-SPD|army_siege_damage_percent(golem);army_stat_percent(speed,golem)|active?:10:00|COST+intelligence|siege/march|mixed:mechanisms-ready;lifecycle-unresolved
402|錬成の匠|TC|T-TRAIN-SPEED|city_training_speed_percent(rook,bishop,knight)|active?:10:00|COST+intelligence|training|mixed:mechanism-ready;lifecycle-unresolved
403|錬成の紋章|TC|T-TRAIN-SPEED|city_training_speed_percent(rook,bishop,knight)|active?:10:00|COST|training|mixed:mechanism-ready;lifecycle-unresolved
404|錬成新時代|TC|T-TRAIN-SPEED-COST|city_training_speed_percent(rook,bishop,knight);city_training_cost_reduction_percent(rook,bishop,knight)|active?:20:00|COST;fixed|training/time/cost|mixed:mechanisms-ready;lifecycle-unresolved;source-anomaly
405|閃光散華|CB|T-ATKSPD-RETURN|army_stat_percent(attack,all);army_stat_percent(speed,all);army_return_speed_percent|active?:60:00|COST|battle/march/return|mixed:mechanisms-ready;lifecycle-unresolved
406|開宝の奇跡|TR|T-TREASURE-EMPTY-ATKSPD|army_stat_percent(attack,all);army_stat_percent(speed,all);treasure_empty_rate_reduction|active?:20:00|COST;fixed|battle/march/treasure|mixed:implemented+placeholder(no-treasure-state)
407|開宝の祈り|TR|T-TREASURE-EMPTY-ATKSPD|army_stat_percent(attack,all);army_stat_percent(speed,all);treasure_empty_rate_reduction|active?:20:00|COST;fixed|battle/march/treasure|mixed:implemented+placeholder(no-treasure-state);source-anomaly
408|開宝の祝福|TR|T-TREASURE-EMPTY-ATKSPD|army_stat_percent(attack,all);army_stat_percent(speed,all);treasure_empty_rate_reduction|active?:20:00|COST;fixed|battle/march/treasure|mixed:implemented+placeholder(no-treasure-state)
409|防戦万全|BA|T-CITY-DEF|city_defense_percent(all)|continuous:00:00|COST|city/defense|adapted:source-set-base-to-assigned-city-defense
410|防戦完璧|BA|T-CITY-DEF|city_defense_percent(all)|continuous:00:00|COST|city/defense|adapted:source-set-base-to-assigned-city-defense
411|防戦極致|BA|T-CITY-DEF|city_defense_percent(all)|continuous:00:00|COST|city/defense|adapted:source-set-base-to-assigned-city-defense
412|防戦準備|BA|T-CITY-DEF|city_defense_percent(all)|continuous:00:00|COST|city/defense|adapted:source-set-base-to-assigned-city-defense
413|防戦無欠|BA|T-CITY-DEF|city_defense_percent(all)|continuous:00:00|COST|city/defense|adapted:source-set-base-to-assigned-city-defense
414|集気刃|HT|T-ATKSPD-ONWIN|army_stat_percent(attack,character);army_stat_percent(speed,character);character_automata_scope;heal_on_battle_success;tension_change(on-success)|active?+event:20:00|COST;fixed|battle/march/post-battle|mixed:source-character-scope+placeholder(no-post-battle-action-snapshot)
415|集気連刃|HT|T-ATKSPD-ONWIN|army_stat_percent(attack,character);army_stat_percent(speed,character);character_automata_scope;heal_on_battle_success;tension_change(on-success)|active?+event:30:00|COST;fixed|battle/march/post-battle|mixed:source-character-scope+placeholder(no-post-battle-action-snapshot)
416|零の領域|CB|T-ATKSPD-CHAR|army_stat_percent(attack,character);army_stat_percent(speed,character);character_automata_scope|active?:60:00|COST|battle/march|mixed:source-character-scope-unsupported;lifecycle-unresolved
417|風土の息吹|RC|T-COLLECT|resource_collection_percent(wind,earth)|active?:10:00|COST|resource-map|placeholder:no-resource-node-assignment
418|風土の恵み|RC|T-COLLECT|resource_collection_percent(wind,earth)|active?:10:00|COST|resource-map|placeholder:no-resource-node-assignment
419|風火の息吹|RC|T-COLLECT|resource_collection_percent(wind,fire)|active?:10:00|COST|resource-map|placeholder:no-resource-node-assignment
420|風火の恵み|RC|T-COLLECT|resource_collection_percent(wind,fire)|active?:10:00|COST|resource-map|placeholder:no-resource-node-assignment
421|風精の大豊穣|RC|T-COLLECT|resource_collection_percent(wind)|active?:10:00|COST|resource-map|placeholder:no-resource-node-assignment
422|風精の息吹|RC|T-COLLECT|resource_collection_percent(wind)|active?:10:00|COST|resource-map|placeholder:no-resource-node-assignment
423|風精の恵み|RC|T-COLLECT|resource_collection_percent(wind)|active?:10:00|COST|resource-map|placeholder:no-resource-node-assignment
424|風精の豊穣|RC|T-COLLECT|resource_collection_percent(wind)|active?:10:00|COST|resource-map|placeholder:no-resource-node-assignment
425|飛援|RF|T-REINFORCE-SPD|army_stat_percent(speed,all);reinforcement_only_modifier|event:10:00|COST|reinforcement/march|placeholder:no-authoritative-reinforcement-identity
426|騎士の光速|CB|T-SPD-UNIT|army_stat_percent(speed,knight)|active?:10:00|COST|march|mixed:mechanism-ready;lifecycle-unresolved
427|騎士の剛守|DF|T-DEF-UNIT-ADV|army_stat_percent(defense,knight);army_stat_percent(defense,high-scout);advanced_unit_scope(high-scout)|active?:10:00|COST|battle/defense|mixed:implemented+placeholder(no-advanced-unit);lifecycle-unresolved
428|騎士の加速|CB|T-SPD-UNIT|army_stat_percent(speed,knight)|active?:10:00|COST|march|mixed:mechanism-ready;lifecycle-unresolved
429|騎士の喝采|BA|T-TIMED-ATK-UNIT|army_stat_percent(attack,knight)|timed?:48:00(no-duration)|COST|battle|mixed:mechanism-ready;duration-missing
430|騎士の堅守|DF|T-DEF-UNIT-ADV|army_stat_percent(defense,knight);army_stat_percent(defense,high-scout);advanced_unit_scope(high-scout)|active?:10:00|COST|battle/defense|mixed:implemented+placeholder(no-advanced-unit);lifecycle-unresolved
431|騎士の境地|CB|T-ATKSPD-UNIT|army_stat_percent(attack,knight);army_stat_percent(speed,knight)|continuous:00:00|COST|battle/march|implemented:continuous
432|騎士の士気|CB|T-ATK-UNIT|army_stat_percent(attack,knight)|continuous:00:00|COST|battle|implemented:continuous
433|騎士の声援|BA|T-TIMED-ATK-UNIT|army_stat_percent(attack,knight)|timed?:48:00(no-duration)|COST|battle|mixed:mechanism-ready;duration-missing
434|騎士の奥義|CB|T-ATKSPD-UNIT|army_stat_percent(attack,knight);army_stat_percent(speed,knight)|active?:10:00|COST|battle/march|mixed:mechanisms-ready;lifecycle-unresolved
435|騎士の威圧|PP|T-ATK-POPULARITY|army_stat_percent(attack,knight);territory_popularity_damage|active?:10:00|COST|battle/territory-popularity|mixed:implemented+placeholder(no-popularity-state)
436|騎士の守備|DF|T-DEF-UNIT-ADV|army_stat_percent(defense,knight);army_stat_percent(defense,high-scout);advanced_unit_scope(high-scout)|active?:10:00|COST|battle/defense|mixed:implemented+placeholder(no-advanced-unit);lifecycle-unresolved
437|騎士の強襲|CB|T-ATKSPD-UNIT|army_stat_percent(attack,knight);army_stat_percent(speed,knight)|active?:10:00|COST|battle/march|mixed:mechanisms-ready;lifecycle-unresolved
438|騎士の援護|RF|T-REINFORCE-DEFSPD-UNIT|army_stat_percent(defense,knight);army_stat_percent(speed,knight);reinforcement_only_modifier|event:auto/00:00|COST|reinforcement/battle/march|placeholder:no-authoritative-reinforcement-identity
439|騎士の攻撃|CB|T-ATK-UNIT|army_stat_percent(attack,knight)|active?:10:00|COST|battle|mixed:mechanism-ready;lifecycle-unresolved
440|騎士の水耕|RC|T-COLLECT-TRAIN|resource_collection_percent(water);city_training_speed_percent(knight)|active?:10:00|COST|resource-map/training|mixed:implemented+placeholder(no-resource-node-assignment);lifecycle-unresolved
441|騎士の牙突|CR|T-ADJACENT-ATK-UNIT|army_stat_percent(attack,knight);adjacent_allied_territory_scaling|active?:50:00|COST;fixed|battle/map|mixed:implemented+placeholder(no-adjacency-snapshot)
442|騎士の神器|CB|T-ATK-UNIT|army_stat_percent(attack,knight)|continuous:00:00|COST|battle|implemented:continuous
443|騎士の神撃|CB|T-ATK-UNIT|army_stat_percent(attack,knight)|active?:10:00|COST|battle|mixed:mechanism-ready;lifecycle-unresolved
444|騎士の突攻|CB|T-ATKSPD-UNIT|army_stat_percent(attack,knight);army_stat_percent(speed,knight)|active?:10:00|COST|battle/march|mixed:mechanisms-ready;lifecycle-unresolved
445|騎士の衝撃|CB|T-ATK-UNIT|army_stat_percent(attack,knight)|active?:10:00|COST|battle|mixed:mechanism-ready;lifecycle-unresolved
446|騎士の覇気|CB|T-ATK-UNIT|army_stat_percent(attack,knight)|continuous:00:00|COST|battle|implemented:continuous
447|騎士の誇り|CB|T-ATKSPD-MIXEDSCOPE|army_stat_percent(attack,knight);army_stat_percent(attack,non-knight);army_stat_percent(speed,non-knight)|active?:30:00|COST|battle/march|mixed:non-unit-scope-needs-expansion;lifecycle-unresolved
448|騎士の豪撃|CB|T-ATK-UNIT|army_stat_percent(attack,knight)|active?:10:00|COST|battle|mixed:mechanism-ready;lifecycle-unresolved
449|騎士の超光速|CB|T-SPD-UNIT|army_stat_percent(speed,knight)|active?:10:00|COST|march|mixed:mechanism-ready;lifecycle-unresolved
450|騎士の超神器|CB|T-ATK-UNIT|army_stat_percent(attack,knight)|continuous:00:00|COST|battle|implemented:continuous
451|騎士の超神撃|CB|T-ATK-UNIT|army_stat_percent(attack,knight)|active?:30:00|COST|battle|mixed:mechanism-ready;lifecycle-unresolved
452|騎士の速攻|CB|T-SPD-UNIT|army_stat_percent(speed,knight)|active?:10:00|COST|march|mixed:mechanism-ready;lifecycle-unresolved
453|騎士の進軍|CB|T-ATKSPD-UNIT|army_stat_percent(attack,knight);army_stat_percent(speed,knight)|active?:10:00|COST|battle/march|mixed:mechanisms-ready;lifecycle-unresolved
454|騎士の闘気|CB|T-ATK-UNIT|army_stat_percent(attack,knight)|continuous:00:00|COST|battle|implemented:continuous
455|騎士の防戦|TC|T-SPLIT-DEF-TRAIN|army_stat_percent(defense,automata);army_stat_percent(defense,character);character_automata_split_modifier;city_training_speed_percent(knight)|active?:10:00|COST+intelligence|battle/defense/training|mixed:character-automata-split;lifecycle-unresolved
456|騎士の防衛陣|DF|T-DEF-UNIT-ADV|army_stat_percent(defense,knight);army_stat_percent(defense,high-scout);advanced_unit_scope(high-scout)|active?:10:00|COST|battle/defense|mixed:implemented+placeholder(no-advanced-unit);lifecycle-unresolved
457|騎士の音速|CB|T-SPD-UNIT|army_stat_percent(speed,knight)|active?:10:00|COST|march|mixed:mechanism-ready;lifecycle-unresolved
458|騎士の頂き|CB|T-ATKSPD-UNIT|army_stat_percent(attack,knight);army_stat_percent(speed,knight)|continuous:00:00|COST|battle/march|implemented:continuous
459|騎士の高速|CB|T-SPD-UNIT|army_stat_percent(speed,knight)|active?:10:00|COST|march|mixed:mechanism-ready;lifecycle-unresolved
460|騎士募集|TC|T-TRAIN-SPEED|city_training_speed_percent(knight)|active?:10:00|COST|training|mixed:mechanism-ready;lifecycle-unresolved
461|騎士急募|TC|T-TRAIN-SPEED|city_training_speed_percent(knight)|active?:10:00|COST|training|mixed:mechanism-ready;lifecycle-unresolved
462|騎士王の理想郷|CB|T-ATK-UNIT|army_stat_percent(attack,knight)|active?:60:00|COST|battle|mixed:mechanism-ready;lifecycle-unresolved
463|騎士移送|UT|T-TRANSFER|unit_transfer_on_reinforcement(knight)|event:48:00|fixed|reinforcement/ownership|placeholder:no-unit-transfer-transaction
464|騎霊の叡智|RC|T-COLLECT|resource_collection_percent(fire,water,earth)|active?:20:00|COST+intelligence|resource-map|placeholder:no-resource-node-assignment
465|騎霊の息吹|RC|T-COLLECT|resource_collection_percent(fire,water,earth)|active?:10:00|COST|resource-map|placeholder:no-resource-node-assignment
466|騎霊の恵み|RC|T-COLLECT|resource_collection_percent(fire,water,earth)|active?:10:00|COST|resource-map|placeholder:no-resource-node-assignment
467|黒紫夢想|CB|T-ATKSPD-ALL|army_stat_percent(attack,all);army_stat_percent(speed,all)|active?:60:00|COST|battle/march|mixed:mechanisms-ready;lifecycle-unresolved
468|黒雪姫の誘い|IR|T-ATKSPD-REWARD|army_stat_percent(attack,all);army_stat_percent(speed,all);battle_reward_resource_percent|active?+event:30:00|COST|battle/march/reward|mixed:implemented+placeholder(no-battle-reward-hook)
469|黒魔法少女|SG|T-SIEGE-SPD-FLAT|army_siege_damage_percent(golem);army_stat_percent(speed,golem);army_siege_damage_flat|active?:10:00|COST+intelligence;fixed|siege/march|mixed:mechanisms-ready;lifecycle-unresolved
470|鼓舞の紋章|PP|T-TIMED-POPULARITY-DEF|territory_popularity_damage(reduction,on-defense)|timed?:72:00(no-duration)|fixed|territory-popularity/defense|placeholder:no-popularity-state;duration-missing
<!-- ME_SKILL_MATRIX_END -->

## 4. 同机制上位技能与别名 / Same-mechanism upgrades and aliases

### 4.1 `神聖乙女` 与 `美少女賛歌`

两者不是新的 PHP 机制，也不是数值完全相同的别名；它们是同一个 `T-ATKSPD-ALL` 组合的不同参数版本：

| 技能 | 来源序号 | 完整机制组合 | 攻击 COST 系数 Lv1→Lv10 | 速度 COST 系数 Lv1→Lv10 | CT Lv1→Lv10 | 迁移结论 |
|---|---:|---|---|---|---|---|
| `神聖乙女` | 353 | `army_stat_percent(attack,all)` + `army_stat_percent(speed,all)` | `[34,38,42,46,52,58,64,75,87.5,105]%` | `[23,26,29,32,37,42,47,53,62,75]%` | `[40:00,39:00,38:00,36:30,35:00,33:30,31:30,29:30,27:00,24:00]` | 基础机制已实现；非零 CT 不能充当 duration，来源生命周期待确认。 |
| `美少女賛歌` | 371 | `army_stat_percent(attack,all)` + `army_stat_percent(speed,all)` | `[44,52,60,70,80,92,104,118,136,160]%` | `[30,34,38,44,50,58,66,76,90,110]%` | `[60:00,59:00,58:00,56:50,55:40,54:20,53:00,51:30,50:00,48:00]` | 与上行使用相同模板，只替换数值曲线和 CT 曲线；生命周期同样待确认。 |

因此后台应让两者共享模板和执行器；“更高数值 + 新名字”只产生数据，不产生新的条件分支。

### 4.2 来源完全别名 / Exact source aliases

以下组在来源中拥有相同十级效果曲线和 CT 曲线，矩阵也为每组保留相同模板与机制标签：

| 别名组 | 模板 | 机制组合 |
|---|---|---|
| `2.14の告白` = `万死の境界線` = `月詠ノ花嫁` | `T-ATK-CHAR` | `army_stat_percent(attack,character)` |
| `アマテラス` = `三千世界` | `T-ATK-MULSPD` | `army_stat_percent(attack,all)` + `army_stat_multiplier(speed,all,0.5)` |
| `はーとふるボム` = `赤皇の特権` | `T-ATK-HPCOST` | `army_stat_percent(attack,character)` + `hp_cost_on_attack` |

别名可以引用或复制同一份规范化技能 JSON，但不得复制执行代码。第三组仍包含 `hp_cost_on_attack` 占位组件；别名关系不会把未实现事件变成已实现。

## 5. 审计结论 / Audit conclusion

- 矩阵恰含 470 行，并与来源的序号和名称逐条一致。
- 每行保留一个既有互斥主分类，同时在机制列完整列出复合技能的全部子组件；主分类不再遮蔽次要效果。
- `continuous`、`instant`、`event`、`timed?`、`active?` 和 `unknown` 分开记录。任何 `H:MM` 都只代表来源 CT。
- 机制可执行性与来源整张技能的可无损迁移性分开判断：非零 CT 战斗修正通常是 `mixed`，缺失权威状态或事件的机制保持 `placeholder`，明确适配的六资源、元素、驻城和待机映射标为 `adapted`。
