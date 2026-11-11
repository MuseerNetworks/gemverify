/**
 * GemVerify User Portal — Dynamic BVN Enrollment Banks Sync
 * Keeps window.GV_ENROLLMENT_BANKS in sync with the database.
 */
(function () {
  'use strict';

  window.GV_ENROLLMENT_BANKS = window.GV_ENROLLMENT_BANKS || [];

  function fetchEnrollmentBanks() {
    fetch('../api/enrollment-banks')
      .then(function (res) { return res.json(); })
      .then(function (json) {
        if (json && json.success && json.data && Array.isArray(json.data.banks) && json.data.banks.length > 0) {
          window.GV_ENROLLMENT_BANKS.length = 0;
          window.GV_ENROLLMENT_BANKS.push.apply(window.GV_ENROLLMENT_BANKS, json.data.banks);
        }
      })
      .catch(function () {});
  }

  // Initial fetch on script load
  fetchEnrollmentBanks();

  // Re-sync when user switches back to this tab
  window.addEventListener('focus', fetchEnrollmentBanks);

  // Expose manual trigger if needed
  window.refreshEnrollmentBanks = fetchEnrollmentBanks;
})();
