/**
 * @file
 * Override polyfill for HTML5 date input and provide support for custom date formats.
 */

(function ($, once, Drupa, tus, drupalSettings) {

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
      once('tus_attache','.webform_strawberryfield_tus', context).forEach(
        ($managed_file_wrapper) => {
          $managed_file_wrapper.querySelectorAll('input[type="file"]').forEach(
            ($input) => {

              let upload = null
              let uploadIsRunning = false
              const toggleBtn = $managed_file_wrapper.querySelector('.tus-btn')
              const input = $input
              $input.classList.toggle('hidden');
              const hidden_input_for_files = $managed_file_wrapper.querySelector('input[type="hidden"]')
              const progress = $managed_file_wrapper.querySelector('.tus-progress')
              const progressBar = progress.querySelector('.tus-bar')
              progressBar.style.minHeight = '1rem';
              progressBar.style.backgroundColor = 'blue';
              progressBar.style.width = '0px';
              progressBar.style.textAlign = 'center';
              progressBar.style.overflow = 'hidden';
              const uploadList = $managed_file_wrapper.querySelector('.tus-upload-list')
              // Really hard to pass even data attributes on webform elements. But this does the trick
              const webformkey = hidden_input_for_files.name.replace('[fids]','')
              // Only for testing/dev! this varies between forms/key elements and will be set
              // by the webform element via settings.
              // If we set more, the PHP backend (tus-php) won't know how to deal with
              // the missing metadata (metadataForPartialUploads)
              const parallelCountConfig= 1;
              const url_string = drupalSettings.webform_strawberryfield.tus[webformkey].url.split('?')[0];
              const token = drupalSettings.webform_strawberryfield.tus[webformkey]["X-CSRF-Token"];
              const endpoint = url_string;
              const token_headers = {"X-CSRF-Token": token }

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
                // We can't use it until TUS-PHP supports this.
                let parallelUploads = Number.parseInt(parallelCountConfig, 10)
                if (Number.isNaN(parallelUploads)) {
                  parallelUploads = 1
                }

                toggleBtn.textContent = 'pause upload'

                const options = {
                  endpoint: endpoint,
                  retryDelays: [0, 1000, 3000],
                  headers: token_headers,
                  removeFingerprintOnSuccess: true,
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
                    progressBar.style.width = `${percentage}%`;
                    progressBar.style.color = 'white';
                    progressBar.innerHTML = `${percentage}%`;
                    console.log(bytesUploaded, bytesTotal, `${percentage}%`)
                  },
                  onSuccess(payload) {
                    const { lastResponse } = payload
                    const anchor = document.createElement('a');
                    anchor.textContent = `Download ${upload.file.name} (${upload.file.size} bytes)`;
                    anchor.href = upload.url;
                    anchor.className = 'btn btn-success';
                    uploadList.appendChild(anchor);
                    const endpoint_complete = upload.url.replace('/tus_upload/','/tus_upload_complete/');
                    var xhr = new XMLHttpRequest();
                    xhr.withCredentials = false;
                    xhr.open('POST', endpoint_complete, true);
                    xhr.setRequestHeader('X-CSRF-Token', token);
                    xhr.setRequestHeader('Content-Type', 'application/json;charset=UTF-8');
                    xhr.timeout = 4000; // time in milliseconds
                    try {
                      xhr.ontimeout = (e) => {
                        if (window.confirm(`File Timeout: ${e.reason}\nDo you want to retry?`)) {
                          upload.start()
                          uploadIsRunning = true;
                          return
                        }
                        else {
                          uploadIsRunning = false;
                          reset()
                        }
                      };

                      xhr.onload = (e) => {
                        if (xhr.readyState === 4) {
                          if (xhr.status === 200) {
                            const fid = JSON.parse(req.responseText);
                            if (fid?.fid) {
                              const previous_fids = hidden_input_for_files.value.split(" ").filter((e, i, self) => i === self.indexOf(e));
                              if (!previous_fids.includes(fid?.fid)) {
                                previous_fids.push(fid?.fid);
                                hidden_input_for_files.value = previous_fids.join(" ");
                              }
                            }
                            else {
                              if (window.confirm(`Server could not find your File, might be busy or out of space: \nDo you want to retry?`)) {
                                upload.start()
                                uploadIsRunning = true;
                                return
                              }
                              else {
                                uploadIsRunning = false;
                                reset()
                              }
                            }
                            console.log(xhr.responseText);
                            progressBar.style.width = '0px';
                            progressBar.innerHTML = '';
                          } else {
                            console.error(xhr.statusText);
                          }
                        }
                      };
                      xhr.onerror = (e) => {
                        console.error(xhr.statusText);
                      };

                      xhr.send(JSON.stringify({
                        fileName: this.metadata.filename
                      }));
                    }
                    catch (e) {
                      console.log(e);
                    }
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
                  startUpload();
                  progressBar.style.width = '0px';
                } else {
                  input.click()
                  progressBar.style.width = '0px';
                }
              })
              input.addEventListener('change', startUpload)

            })
        })
    }
  };
  Drupal.file.triggerUploadButton = function(event) {
    if (event.target.closest('.webform_strawberryfield_tus')) {
      // Avoid calling the Form Submit Default File handler if this is TUS.
      // WE might want to remove that class IF !tus.isSupported is false ..
      // or call here !tus.isSupported ?
      return;
    } else {
      $(event.target)
        .closest('.js-form-managed-file')
        .find('.js-form-submit[data-drupal-selector$="upload-button"]')
        .trigger('mousedown');
    }
  };

})(jQuery, once, Drupal, tus, drupalSettings);
