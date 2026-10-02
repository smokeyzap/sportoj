# Acceptatietests: resultaat van een echte run

Gegenereerd met `tests/acceptance_report.php` uit het JUnit-log van PHPUnit. Totaal in de suite: 260 tests, 12156 asserts, 0 gefaald, 0 overgeslagen.

| AT | Omschrijving | Resultaat | Tests |
|---|---|---|---|
| AT-001 | Schema installeert schoon | geslaagd | `InstallTest::AT001SchemaInstallsCleanlyOnAnEmptyDatabase` |
| AT-002 | Seed laadt compleet | geslaagd | `DatasetTest::AT002SeedIsComplete` |
| AT-003 | Datasetmetadata klopt | geslaagd | `DatasetTest::AT003DatasetMetadata` |
| AT-004 | Programmastructuur klopt | geslaagd | `DatasetTest::AT004ProgramStructure` |
| AT-010 | Geldige login | geslaagd | `AuthTest::AT010ValidLoginReturnsUserCsrfAndSecureCookie` |
| AT-011 | Ongeldige login | geslaagd | `AuthTest::AT011InvalidLoginDoesNotRevealWhichFieldIsWrong` |
| AT-012 | CSRF voor mutatie | geslaagd | `AuthTest::AT012MutationRequiresValidCsrfToken` |
| AT-013 | Logout | geslaagd | `AuthTest::AT013LogoutInvalidatesServerSideSession` |
| AT-014 | Expired session | geslaagd | `AuthTest::AT014ExpiredSessionIsRejectedAndStaysInvalid` |
| AT-015 | Rate limit login | geslaagd | `AuthTest::AT015LoginRateLimit` |
| AT-020 | Start vanaf begin | geslaagd | `ProgramStartTest::AT020StartFromBeginning` |
| AT-021 | Start week 6 donderdag | geslaagd | `ProgramStartTest::AT021StartWeek6Thursday` |
| AT-022 | Ongeldige startpositie | geslaagd | `ProgramStartTest::AT022InvalidStartPosition`<br>`ProgramStartTest::AT022InvalidStartPosition`<br>`ProgramStartTest::AT022InvalidStartPosition`<br>`ProgramStartTest::AT022InvalidStartPosition` |
| AT-023 | Geen tweede actieve run | geslaagd | `ProgramStartTest::AT023NoSecondActiveRun` |
| AT-030 | Kalenderdag negeren | geslaagd | `RecommendationTest::AT030CalendarDaysAreIgnored` |
| AT-031 | Completed overslaan in recommendation | geslaagd | `RecommendationTest::AT031CompletedAreSkipped` |
| AT-032 | Skipped overslaan in recommendation | geslaagd | `RecommendationTest::AT032SkippedAreSkipped` |
| AT-033 | Started heeft prioriteit | geslaagd | `RecommendationTest::AT033StartedHasPriorityAsResume` |
| AT-034 | Maximaal één started program assignment | geslaagd | `RecommendationTest::AT034OnlyOneStartedProgramAssignment` |
| AT-040 | Start pending | geslaagd | `LifecycleTest::AT040StartMovesPendingToStartedWithOneProgramSession` |
| AT-041 | Start opnieuw is idempotent | geslaagd | `LifecycleTest::AT041StartTwiceReturnsSameSession` |
| AT-042 | Complete normaal | geslaagd | `LifecycleTest::AT042CompleteNormally` |
| AT-043 | Complete direct vanaf pending | geslaagd | `LifecycleTest::AT043CompleteDirectlyFromPending`<br>`LifecycleTest::AT043DirectCompleteOfNonRecommendedRegistersNotRecommended` |
| AT-044 | Dubbel complete | geslaagd | `LifecycleTest::AT044DoubleCompleteIsIdempotent` |
| AT-045 | Notitie | geslaagd | `LifecycleTest::AT045NotesAreStoredAndReturnedViaHistory` |
| AT-050 | Afwijkende workout starten | geslaagd | `LifecycleTest::AT050DeviatingWorkoutIsRegisteredAsNotRecommended` |
| AT-051 | Afwijkende workout complete | geslaagd | `ContinuationTest::AT051DeviatingCompletionRaisesContinuationDecision` |
| AT-052 | Keuze program_sequence | geslaagd | `ContinuationTest::AT052ChooseProgramSequence` |
| AT-053 | Keuze last_workout_sequence | geslaagd | `ContinuationTest::AT053ChooseLastWorkoutSequence` |
| AT-054 | Anchor schuift mee | geslaagd | `ContinuationTest::AT054AnchorMovesAlongWithoutNewDecision` |
| AT-055 | Eerdere gaten blijven bestaan | geslaagd | `ContinuationTest::AT055EarlierGapsStayPending` |
| AT-056 | Automatische fallback | geslaagd | `ContinuationTest::AT056AutomaticFallbackWhenNothingFollowsTheAnchor` |
| AT-057 | Opnieuw afwijken | geslaagd | `ContinuationTest::AT057DeviatingAgainWhileOnLastWorkoutSequenceAsksAgain` |
| AT-060 | Skip pending | geslaagd | `SkipReopenTest::AT060SkipPending` |
| AT-061 | Skip started | geslaagd | `SkipReopenTest::AT061SkipStartedCancelsTheActiveSession` |
| AT-062 | Reopen skipped | geslaagd | `SkipReopenTest::AT062ReopenSkipped` |
| AT-063 | Reopen completed | geslaagd | `SkipReopenTest::AT063ReopenCompletedCorrectsTheSessionWithoutDeleting` |
| AT-064 | Reopen kan recommendation terugtrekken | geslaagd | `SkipReopenTest::AT064ReopenCanPullTheRecommendationBack` |
| AT-070 | Extra workout starten | geslaagd | `ExtraWorkoutTest::AT070StartExtraWorkout` |
| AT-071 | Extra workout complete | geslaagd | `ExtraWorkoutTest::AT071CompleteExtraAppearsInHistory` |
| AT-072 | Extra workout beïnvloedt programma niet | geslaagd | `ExtraWorkoutTest::AT072ExtraWorkoutDoesNotTouchProgress` |
| AT-073 | Future block workout als extra | geslaagd | `ExtraWorkoutTest::AT073FutureBlockWorkoutAsExtraButAssignmentStaysLocked` |
| AT-080 | Cyclus 1 voltooid | geslaagd | `CycleTest::AT080FinishedCycleContinuesAutomaticallyInTheNextOne` |
| AT-081 | Geen voortgang bij pending | geslaagd | `CycleTest::AT081NextCycleDoesNotStartWhileAnOpenPositionRemains` |
| AT-082 | prior_to_start telt als verwerkt | geslaagd | `CycleTest::AT082PriorToStartCountsAsProcessed` |
| AT-090 | Laatste standaardcyclus verwerkt | geslaagd | `BlockDecisionTest::AT090LastStandardCycleProcessedGivesBlockDecision` |
| AT-091 | Extend maakt exact zes | geslaagd | `BlockDecisionTest::AT091ExtendAddsExactlySixExtraAssignments` |
| AT-092 | Extend eerste recommendation | geslaagd | `BlockDecisionTest::AT092AfterExtendTheFirstPositionOfTheExtraCycleIsRecommended` |
| AT-093 | Dubbel extend | geslaagd | `BlockDecisionTest::AT093RepeatedExtendDoesNotAddASecondCycle`<br>`BlockDecisionTest::AT093ConcurrentExtendCreatesOnlyOneExtraCycle` |
| AT-094 | Extra cyclus opnieuw afgerond | geslaagd | `BlockDecisionTest::AT094ExtraCycleCompletedGivesDecisionAgainAndCanBeExtendedAgain` |
| AT-095 | Advance | geslaagd | `BlockDecisionTest::AT095AdvanceActivatesTheNextBlock` |
| AT-096 | Advance te vroeg | geslaagd | `BlockDecisionTest::AT096EarlyDecisionsAre409` |
| AT-097 | Continuation reset bij block advance | geslaagd | `BlockDecisionTest::AT097ContinuationIsResetOnAdvance` |
| AT-100 | Laatste block decision | geslaagd | `BlockDecisionTest::AT100LastBlockOffersExtendAndCompleteProgramButNotAdvance` |
| AT-101 | Program complete | geslaagd | `BlockDecisionTest::AT101CompleteProgram` |
| AT-102 | Program restart | geslaagd | `BlockDecisionTest::AT102RestartCreatesANewRunAndKeepsTheOldOne` |
| AT-103 | Oude historie na restart | geslaagd | `BlockDecisionTest::AT103HistoryAcrossRunsKeepsIdsApart` |
| AT-110 | Historie volgorde | geslaagd | `HistoryTest::AT110NewestEventFirst` |
| AT-111 | prior_to_start verborgen | geslaagd | `HistoryTest::AT111PriorToStartIsNeverShown` |
| AT-112 | Skipped zichtbaar | geslaagd | `HistoryTest::AT112SkippedAppearsAsItsOwnItem` |
| AT-113 | Pagination | geslaagd | `HistoryTest::AT113Pagination` |
| AT-120 | Assignment van andere user lezen | geslaagd | `SecurityTest::AT120ReadingAnotherUsersAssignmentIs403` |
| AT-121 | Assignment van andere user wijzigen | geslaagd | `SecurityTest::AT121MutatingAnotherUsersAssignmentIsImpossible` |
| AT-122 | Session van andere user complete | geslaagd | `SecurityTest::AT122CompletingAnotherUsersSessionIs403` |
| AT-123 | Public ULID is geen autorisatie | geslaagd | `SecurityTest::AT123KnowingAValidUlidGivesNoAccess` |
| AT-124 | Database-ID's niet exponeren | geslaagd | `SecurityTest::AT124InternalDatabaseIdsAreNeverExposed` |
| AT-130 | Unieke assignment | geslaagd | `IntegrityTest::AT130DuplicateAssignmentCombinationIsRejectedByTheDatabase` |
| AT-131 | Transactionele complete | geslaagd | `IntegrityTest::AT131FailureInTheMiddleOfCompleteRollsEverythingBack` |
| AT-132 | Transactionele extend | geslaagd | `IntegrityTest::AT132FailureInTheMiddleOfExtendLeavesNoPartialCycle` |
| AT-133 | UTC | geslaagd | `IntegrityTest::AT133TimestampsAreStoredInUtc` |
| AT-140 | OpenAPI validatie | geslaagd | `ContractTest::AT140RoutesAndSpecAreTheSameSet`<br>`ContractTest::AT140EveryOperationIsExercisedWithAConformingResponse` |
| AT-141 | Error envelope | geslaagd | `ContractTest::AT141ErrorEnvelopeIsUniform` |
| AT-142 | API versie | geslaagd | `ContractTest::AT142OnlyApiV1AndHealthExist` |
| AT-143 | CORS credentials | geslaagd | `AuthTest::AT143CorsOnlyForExplicitOriginsAndNeverWildcard` |
| AT-150 | Dagelijkse backup produceert geldig bestand | geslaagd | `BackupRestoreTest::AT150BackupProducesAValidFile` |
| AT-151 | Restore test | geslaagd | `BackupRestoreTest::AT151And152RestoreTestIntoAnEmptyTemporaryDatabase` |
| AT-152 | Restore inhoud | geslaagd | `BackupRestoreTest::AT151And152RestoreTestIntoAnEmptyTemporaryDatabase` |
| AT-160 | Zelfde testset PHP en Laravel | buiten fase 1a |  |
| AT-161 | Geen data reset | buiten fase 1a |  |
| AT-162 | API client ongewijzigd | buiten fase 1a |  |
| AT-163 | Progressie identiek | buiten fase 1a |  |
| AT-164 | Rollback | buiten fase 1a |  |
