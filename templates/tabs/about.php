<!-- about tab -->
<div class="tab-pane fade" id="docker-about" role="tabpanel">
  <h4 class="mt-3 mb-3"><?php echo _("About"); ?></h4>
  <dl class="row">
    <dt class="col-sm-3"><?php echo _("Plugin"); ?></dt>
    <dd class="col-sm-9">Docker</dd>

    <dt class="col-sm-3"><?php echo _("Version"); ?></dt>
    <dd class="col-sm-9">
      <?php echo htmlspecialchars($__template_data['pluginVersion'] ?? 'unknown'); ?>
      <span id="docker-update-badge" class="badge bg-secondary ms-2 d-none"></span>
    </dd>

    <dt class="col-sm-3"><?php echo _("Description"); ?></dt>
    <dd class="col-sm-9"><?php echo htmlspecialchars($__template_data['description']); ?></dd>

    <dt class="col-sm-3"><?php echo _("Author"); ?></dt>
    <dd class="col-sm-9"><a href="<?php echo htmlspecialchars($__template_data['uri']); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($__template_data['author']); ?></a></dd>

    <dt class="col-sm-3"><?php echo _("Docker CE"); ?></dt>
    <dd class="col-sm-9">
      <?php echo !empty($__template_data['dockerVersion']) ? htmlspecialchars($__template_data['dockerVersion']) : _("Not installed"); ?>
    </dd>

    <dt class="col-sm-3"><?php echo _("Documentation"); ?></dt>
    <dd class="col-sm-9"><a href="https://docs.raspap.com/docker" target="_blank" rel="noopener">docs.raspap.com/docker</a></dd>
  </dl>

  <!-- Update Check -->
  <div class="card mt-3">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h6 class="mb-0"><i class="fas fa-cloud-download-alt me-1"></i><?php echo _("Plugin Updates"); ?></h6>
        <button type="button" class="btn btn-outline-primary btn-sm" id="docker-check-update-btn">
          <i class="fas fa-sync-alt me-1"></i><?php echo _("Check for Updates"); ?>
        </button>
      </div>
      <div id="docker-update-status"></div>
    </div>
  </div>

  <div class="col-6 mb-3 mt-3">
    GitHub <i class="fa-brands fa-github"></i> <a href="<?php echo htmlspecialchars($__template_data['pluginUri'] ?? $__template_data['uri']); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($__template_data['pluginName']); ?></a>
  </div>
</div><!-- /.tab-pane -->
