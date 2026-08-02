<?php
/** Language management panel modals. Runs in modals.php scope. */
if (!defined('APP_ROOT')) {
	exit;
}
?>
<!-- Duplicate a language under a new code (pt 6). The two shipped languages are never
     overwritten by an upload, so this is how a customised PL/EN wording is made. -->
<div class="modal-bg" id="languageDuplicateModal">
	<div class="modal modal-sm">
		<div class="modal-header">
			<h3><i class="fa-solid fa-copy"></i> <?= _h('panel.lang.duplicate') ?></h3>
			<button class="btn-icon modal-close" data-fh-click="closeModal('languageDuplicateModal')"><i class="fa-solid fa-xmark"></i></button>
		</div>
		<div class="modal-body">
			<div id="languageDuplicateMessage" class="auth-message"></div>
			<p style="color:var(--text-muted); font-size:0.87rem; margin:0 0 14px;">
				<?= __('panel.lang.duplicate_intro', ['code' => '<strong id="ldSource">—</strong>']) ?>
			</p>
			<?php /* pt 7: the code field used to be a stubby 150px box parked at the left of an
			         otherwise empty row, with the hint stretching well past it. It now sits in a
			         "source → new" row that fills the width and shows what the copy will become. */ ?>
			<div class="form-group">
				<label for="ldCode"><?= _h('panel.lang.new_code') ?></label>
				<div class="lang-code-row">
					<span class="lang-code-from" id="ldSourceBadge">—</span>
					<i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
					<input type="text" id="ldCode" class="lang-code-input" maxlength="3" autocomplete="off"
						spellcheck="false" placeholder="<?= _h('panel.lang.code_ph') ?>"
						data-fh-keydown="submitPanelOnEnter(event, 'duplicateLanguage')">
				</div>
				<small><?= _h('panel.lang.code_hint') ?></small>
			</div>
			<div class="modal-btns">
				<button type="button" class="btn" data-fh-click="closeModal('languageDuplicateModal')"><?= _h('common.cancel') ?></button>
				<button type="button" class="btn btn-primary" data-fh-click="submitDuplicateLanguage()"><?= _h('panel.lang.duplicate') ?></button>
			</div>
		</div>
	</div>
</div>

<!-- Install a language from a JSON file (Settings → Languages) -->
<div class="modal-bg" id="languageUploadModal">
	<div class="modal">
		<div class="modal-header">
			<h3><i class="fa-solid fa-language"></i> <?= _h('panel.lang.add') ?></h3>
			<button class="btn-icon modal-close" data-fh-click="closeModal('languageUploadModal')"><i class="fa-solid fa-xmark"></i></button>
		</div>
		<div class="modal-body">
			<div id="languageUploadMessage" class="auth-message"></div>
			<p style="color:var(--text-muted); font-size:0.87rem; margin:0 0 16px;"><?= _h('panel.lang.upload_intro') ?></p>

			<!-- Step 1: start from an existing language -->
			<div class="lang-step">
				<span class="lang-step-num">1</span>
				<div class="lang-step-body">
					<h4><?= _h('panel.lang.step_template') ?></h4>
					<p><?= _h('panel.lang.template_hint') ?></p>
					<button type="button" class="btn" data-fh-click="downloadLanguageTemplate()">
						<i class="fa-solid fa-download"></i> <?= _h('panel.lang.template') ?>
					</button>
				</div>
			</div>

			<!-- Step 2: which language is it -->
			<div class="lang-step">
				<span class="lang-step-num">2</span>
				<div class="lang-step-body">
					<h4><?= _h('panel.lang.step_code') ?></h4>
					<div class="form-group">
						<input type="text" id="langCode" maxlength="3" placeholder="<?= _h('panel.lang.code_ph') ?>"
							style="max-width:150px;" autocomplete="off" data-fh-input="onLanguageCodeInput()">
						<div class="lang-code-name" id="langCodeName"></div>
						<small><?= _h('panel.lang.code_hint') ?></small>
					</div>
					<div class="lang-suggestions" id="langSuggestions"></div>
				</div>
			</div>

			<!-- Step 3: the translated file -->
			<div class="lang-step">
				<span class="lang-step-num">3</span>
				<div class="lang-step-body">
					<h4><?= _h('panel.lang.step_file') ?></h4>
					<div class="form-group">
						<input type="file" id="langFile" accept=".json,application/json" class="input" data-fh-change="onLanguageFilePicked()">
						<small id="langFileInfo"><?= _h('panel.lang.file_hint') ?></small>
					</div>
				</div>
			</div>

			<div class="modal-btns">
				<button type="button" class="btn" data-fh-click="closeModal('languageUploadModal')"><?= _h('common.cancel') ?></button>
				<button type="button" class="btn btn-primary" data-fh-click="submitLanguageUpload()"><?= _h('panel.lang.install') ?></button>
			</div>
		</div>
	</div>
</div>

