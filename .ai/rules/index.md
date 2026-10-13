# Project Rules Index

Before planning or editing, find the row whose globs match the file's path and read that rule file.

| Applies to | Rule file |
| --- | --- |
| app/Actions/RollOverFundingWindow.php, app/Actions/ReserveVendorPayment.php, app/Actions/ReviewVendorPayment.php | .ai/rules/actions.md |
| app/Services/Advisory/** | .ai/rules/advisory.md |
| app/Ai/**/*.php, tests/Feature/Ai/**/*.php, config/ai.php | .ai/rules/ai-agents.md |
| app/**/*.php | .ai/rules/app.md |
| app/Actions/CaptureCollectionBatch.php, app/Actions/ImportTuitionPaymentAsCollection.php, app/Models/CollectionBatch.php, app/Services/TuitionReceiptIntake.php, app/Jobs/ReconcileTuitionReceipt.php, app/Console/Commands/ReconcileTuitionReceipts.php | .ai/rules/collections.md |
| app/Http/Controllers/** | .ai/rules/controllers.md |
| resources/css/**/*.css, resources/css/bulletin.css | .ai/rules/css.md |
| app/Services/StudentTuitionPayment*.php, app/Models/StudentTuitionPayment*.php, app/Enums/StudentTuitionPaymentState.php, app/Services/Payments/*Transport.php, app/Jobs/ProcessStudentTuitionPayment.php, app/Console/Commands/DispatchStudentTuitionPayments.php, app/Http/Controllers/StudentTuitionPaymentController.php, database/migrations/*student_tuition_payment*.php, tests/Feature/StudentTuition*.php | .ai/rules/feature.md |
| postcss.config.js, composer.json | .ai/rules/general.md |
| app/Services/Lepton*.php, app/Services/Lepton/**/*.php, app/Console/Commands/Lepton*.php, app/Console/Commands/EduFlowDemo.php, database/seeders/**/*Lepton*.php, database/seeders/**/*Financial*.php, app/Services/ArcSettlementVerifier.php, app/Services/IsolatedPaymentExecutor.php, app/Services/ReservationCapacity.php, app/Services/MandateOccurrenceScanner.php, app/Services/RecordRetrospectiveAgreement.php | .ai/rules/lepton.md |
| app/Services/StudentWallet*.php, app/Services/CircleAgentWalletCli.php, app/Console/Commands/*StudentWallet*.php, database/migrations/*student_wallet*.php, app/Models/StudentWallet*.php, app/Models/PaymentSubmissionOutbox.php | .ai/rules/models.md |
| rector.php | .ai/rules/rector-php.md |
| scripts/** | .ai/rules/scripts.md |
| app/Services/StudentBillPaymentQuote.php, app/Models/StudentBillPayment.php | .ai/rules/services-models.md |
