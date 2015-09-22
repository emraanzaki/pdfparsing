Dropzone.autoDiscover = false;

$(document).ready(function() {

	$('#pdfDropzone').dropzone({ 
	    url: "/parser/public/upload",
	    dictDefaultMessage: "Drop PDF here to begin",
	    dictInvalidFileType: "Unsupported file type. Only PDF allowed.",
	    maxFilesize: 2048,
	    paramName: "file",
	    maxFiles: 1,
	    thumbnailWidth: 260,
	    thumbnailHeight: 260,
	    acceptedFiles: 'application/pdf',
	    init: function() {
	      this.on("success", function(file, response) { 
	      	var serResp = jQuery.parseJSON(response);
	      	$("#success").removeClass('hidden');
	      	$("#success .alert").html(serResp.message); 
	      	$("#pdfDropzone").fadeOut("slow");
	      });
	      this.on("error", function(file, errorMessage) { this.removeFile(file); alert(errorMessage);  });
	    }
	});
});