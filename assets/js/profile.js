/**
 * Client-Side Profile & Session Handler for PolyAuth System
 * Manages protected profile data loading, inline profile updates, and Redis logout via jQuery $.ajax
 */

$(document).ready(function () {

  const $loadingOverlay = $('#loadingOverlay');
  const $profileContainer = $('#profileContainer');
  const $alertContainer = $('#alertContainer');
  const $editForm = $('#editProfileForm');
  const $btnSaveProfile = $('#btnSaveProfile');
  const $btnSaveText = $('#btnSaveText');
  const $btnSaveSpinner = $('#btnSaveSpinner');
  const $btnLogout = $('#btnLogout');

  // Retrieve token from localStorage
  const sessionToken = localStorage.getItem('session_token');

  /**
   * Helper to get request headers with Bearer Token
   */
  function getAuthHeaders() {
    const headers = {};
    if (sessionToken) {
      headers['Authorization'] = 'Bearer ' + sessionToken;
    }
    return headers;
  }

  /**
   * Display Custom Alert Banner
   */
  function showAlert(message, type = 'danger') {
    const icon = type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill';
    const alertHtml = `
      <div class="custom-alert custom-alert-${type} fade show mb-4" role="alert">
        <i class="bi ${icon} fs-5"></i>
        <div>${message}</div>
      </div>
    `;
    $alertContainer.html(alertHtml).removeClass('d-none');
    $('html, body').animate({ scrollTop: $alertContainer.offset().top - 100 }, 300);
  }

  /**
   * Hide Alert Banner
   */
  function hideAlert() {
    $alertContainer.addClass('d-none').empty();
  }

  /**
   * Set Loading State on Save Button
   */
  function setSaveLoading(loading) {
    if (loading) {
      $btnSaveProfile.prop('disabled', true);
      $btnSaveText.text(' Saving Changes...');
      $btnSaveSpinner.removeClass('d-none');
    } else {
      $btnSaveProfile.prop('disabled', false);
      $btnSaveText.html('<i class="bi bi-check2-circle me-2"></i> Save Changes');
      $btnSaveSpinner.addClass('d-none');
    }
  }

  /**
   * Load Profile Data via jQuery AJAX GET
   */
  function loadProfile() {
    $.ajax({
      url: 'php/profile.php',
      type: 'GET',
      headers: getAuthHeaders(),
      dataType: 'json',
      success: function (response) {
        if (response.success && response.user) {
          renderProfile(response.user, response.profile || {});
          $loadingOverlay.addClass('d-none');
          $profileContainer.removeClass('d-none');
        } else {
          handleUnauthorized();
        }
      },
      error: function (xhr) {
        if (xhr.status === 401) {
          handleUnauthorized();
        } else {
          $loadingOverlay.html(`
            <div class="text-danger">
              <i class="bi bi-exclamation-triangle-fill fs-1"></i>
              <p class="mt-3 fw-bold">Failed to load profile data.</p>
              <button class="btn btn-outline-glass btn-sm" onclick="location.reload()">Retry</button>
            </div>
          `);
        }
      }
    });
  }

  /**
   * Populate UI with MySQL user and MongoDB profile data
   */
  function renderProfile(user, profile) {
    // Top banner
    $('#dispName').text(profile.name || user.username);
    $('#dispUsernameBadge').text('@' + user.username);
    $('#dispEmail').text(user.email);
    $('#dispUserId').text(user.id);

    // Left display card
    $('#dispFullName').text(profile.name || 'Not specified');
    $('#dispAge').text(profile.age ? profile.age + ' years old' : 'Not specified');
    $('#dispBio').text(profile.bio || 'No bio provided yet.');
    $('#dispCreatedAt').text(user.created_at || 'N/A');
    $('#dispUpdatedAt').text(profile.updated_at || 'N/A');

    // Render interest tags
    const $interestsContainer = $('#dispInterestsContainer').empty();
    if (profile.interests && profile.interests.length > 0) {
      profile.interests.forEach(function (interest) {
        $interestsContainer.append(`<span class="interest-tag"><i class="bi bi-tag-fill me-1"></i>${escapeHtml(interest)}</span>`);
      });
    } else {
      $interestsContainer.html('<span class="text-muted fs-7">No interests added yet.</span>');
    }

    // Right edit form
    $('#editUsername').val(user.username);
    $('#editEmail').val(user.email);
    $('#editName').val(profile.name || '');
    $('#editAge').val(profile.age || '');
    $('#editBio').val(profile.bio || '');
    $('#editInterests').val(profile.interests ? profile.interests.join(', ') : '');
  }

  /**
   * Escape HTML to prevent XSS in rendered tags
   */
  function escapeHtml(str) {
    return $('<div>').text(str).html();
  }

  /**
   * Handle Unauthorized Access / Session Expiration
   */
  function handleUnauthorized() {
    localStorage.removeItem('session_token');
    window.location.href = 'login.html';
  }

  /**
   * Submit Profile Edits via jQuery AJAX POST
   */
  $editForm.on('submit', function (e) {
    e.preventDefault();
    hideAlert();

    const username = $('#editUsername').val().trim();
    const name = $('#editName').val().trim();
    const age = $('#editAge').val().trim();
    const bio = $('#editBio').val().trim();
    const rawInterests = $('#editInterests').val().trim();

    if (!name) {
      showAlert('Full Name cannot be empty.');
      return;
    }

    if (!age || isNaN(age) || parseInt(age, 10) < 1 || parseInt(age, 10) > 120) {
      showAlert('Please enter a valid age between 1 and 120.');
      return;
    }

    if (username && !/^[a-zA-Z0-9_]{3,30}$/.test(username)) {
      showAlert('Username must be 3-30 characters long (letters, numbers, underscores).');
      return;
    }

    const interests = rawInterests ? rawInterests.split(',').map(item => item.trim()).filter(Boolean) : [];

    const payload = {
      username: username,
      name: name,
      age: parseInt(age, 10),
      bio: bio,
      interests: interests
    };

    setSaveLoading(true);

    $.ajax({
      url: 'php/profile.php',
      type: 'POST',
      headers: getAuthHeaders(),
      contentType: 'application/json',
      data: JSON.stringify(payload),
      dataType: 'json',
      success: function (response) {
        setSaveLoading(false);
        if (response.success) {
          showAlert(response.message || 'Profile updated successfully!', 'success');
          loadProfile(); // Refresh UI
        } else {
          showAlert(response.message || 'Failed to update profile.');
        }
      },
      error: function (xhr) {
        setSaveLoading(false);
        let errorMsg = 'Failed to update profile.';
        if (xhr.responseJSON && xhr.responseJSON.message) {
          errorMsg = xhr.responseJSON.message;
        }
        showAlert(errorMsg);
      }
    });

  });

  /**
   * Handle Logout Click via jQuery AJAX
   */
  $btnLogout.on('click', function (e) {
    e.preventDefault();

    $.ajax({
      url: 'php/logout.php',
      type: 'POST',
      headers: getAuthHeaders(),
      dataType: 'json',
      complete: function () {
        localStorage.removeItem('session_token');
        window.location.href = 'login.html';
      }
    });
  });

  // Initial Load Trigger
  loadProfile();

});
