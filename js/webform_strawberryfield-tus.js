/**
 * @file
 * Override polyfill for HTML5 date input and provide support for custom date formats.
 */

(function (once, Drupa, tus, drupalSettings) {

  'use strict';

  Drupal.webform = Drupal.webform || {};

  /**
   * Attach Tus Uploader functionality.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches the behavior. Accepts in `settings.date` an object listing
   *   elements to process, keyed by the HTML ID of the form element containing
   *   the human-readable value. Each element is an datepicker settings object.
   * @prop {Drupal~behaviorDetach} detach
   *   Detach the behavior destroying datepickers on effected elements.
   */
  Drupal.behaviors.webform_strawberryfield_tus = {
    attach: function (context, settings) {
      once('tus_attache','input[type="file"].webform_strawberryfield_tus', context).forEach(
        ($input) => {

          let upload = null
          let uploadIsRunning = false
          const toggleBtn = document.querySelector('#toggle-btn')
          const input = $input
          const progress = document.querySelector('.progress')
          const progressBar = progress.querySelector('.bar')
          const alertBox = document.querySelector('#support-alert')
          const uploadList = document.querySelector('#upload-list')
          // Only for testing/dev! this varies between forms/key elements and will be set
          // by the webform element via settings.
          const chunkSizeConfig = 512;
          const parallelCountConfog= 2;
          const endpoint = drupalSettings.path.baseUrl + 'webform_strawberry/tus_upload/descriptive_metadata/tus/'

          function reset() {
            input.value = ''
            toggleBtn.textContent = 'start upload'
            upload = null
            uploadIsRunning = false
          }

          function askToResumeUpload(previousUploads, currentUpload) {
            if (previousUploads.length === 0) return

            let text = 'You tried to upload this file previously at these times:\n\n'
            previousUploads.forEach((previousUpload, index) => {
              text += `[${index}] ${previousUpload.creationTime}\n`
            })
            text +=
              '\nEnter the corresponding number to resume an upload or press Cancel to start a new upload'

            const answer = prompt(text)
            const index = Number.parseInt(answer, 10)

            if (!Number.isNaN(index) && previousUploads[index]) {
              currentUpload.resumeFromPreviousUpload(previousUploads[index])
            }
          }

          function startUpload() {
            const file = input.files[0]
            // Only continue if a file has actually been selected.
            // IE will trigger a change event even if we reset the input element
            // using reset() and we do not want to blow up later.
            if (!file) {
              return
            }

            let chunkSize = Number.parseInt(chunkSizeConfig, 10)
            if (Number.isNaN(chunkSize)) {
              chunkSize = Number.POSITIVE_INFINITY
            }

            let parallelUploads = Number.parseInt(parallelCountConfig, 10)
            if (Number.isNaN(parallelUploads)) {
              parallelUploads = 1
            }

            toggleBtn.textContent = 'pause upload'

            const options = {
              endpoint,
              chunkSize,
              retryDelays: [0, 1000, 3000, 5000],
              parallelUploads,
              metadata: {
                filename: file.name,
                filetype: file.type,
              },
              onError(error) {
                if (error.originalRequest) {
                  if (window.confirm(`Failed because: ${error}\nDo you want to retry?`)) {
                    upload.start()
                    uploadIsRunning = true
                    return
                  }
                } else {
                  window.alert(`Failed because: ${error}`)
                }

                reset()
              },
              onProgress(bytesUploaded, bytesTotal) {
                const percentage = ((bytesUploaded / bytesTotal) * 100).toFixed(2)
                progressBar.style.width = `${percentage}%`
                console.log(bytesUploaded, bytesTotal, `${percentage}%`)
              },
              onSuccess() {
                const anchor = document.createElement('a')
                anchor.textContent = `Download ${upload.file.name} (${upload.file.size} bytes)`
                anchor.href = upload.url
                anchor.className = 'btn btn-success'
                uploadList.appendChild(anchor)
                var uploadKey = upload.url.split('/').slice(-1).pop();
                var ajax_settings = {
                  type: 'POST',
                  contentType: 'application/json;charset=utf-8',
                  dataType: 'json',
                  processData: false,
                  data: JSON.stringify({fileName: resp.file.name}),
                  url: drupalSettings.path.baseUrl + 'webform_strawberry/tus_upload_complete/descriptive_metadata/tus/' + uploadKey
                };

                // Send ajax call to inform upload complete, and put value in the field.
                $.ajax(ajax_settings).done(function(response) {
                  // TODO this should update the info the Drupal way.
                  console.log(response.fid);
                });


                reset()
              },
            }

            upload = new tus.Upload(file, options)
            upload.findPreviousUploads().then((previousUploads) => {
              askToResumeUpload(previousUploads, upload)
              upload.start()
              uploadIsRunning = true
            })
          }

          if (!tus.isSupported) {
            alertBox.classList.remove('hidden')
          }

          if (!toggleBtn) {
            throw new Error('Toggle button not found on this page. Aborting upload-demo. ')
          }

          toggleBtn.addEventListener('click', (e) => {
            e.preventDefault()

            if (upload) {
              if (uploadIsRunning) {
                upload.abort()
                toggleBtn.textContent = 'resume upload'
                uploadIsRunning = false
              } else {
                upload.start()
                toggleBtn.textContent = 'pause upload'
                uploadIsRunning = true
              }
            } else if (input.files.length > 0) {
              startUpload()
            } else {
              input.click()
            }
          })

          input.addEventListener('change', startUpload)


    })
    }
  };

})(once, Drupal, tus, drupalSettings);
