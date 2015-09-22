from util.convert import converter
from lib.book import book

def text_to_dict(fileinput):
    """
    extracting the text into a xml string
    """
    convert = converter()
    xml = convert.as_xml().add_input_file(fileinput).run()
    """
    Parsing the xml string to transform it into a dictionary
    """
    b = book(xml)
    return b.toDict()


"""
Extracting the images out of the pdf file
images are named respecting the following convention: tempppm-[pageNumber]-[imageNumber].ppm (eg: tempppm-001-000.ppm)
"""
import subprocess
import sys

def extract_images(file):
    subprocess.call('pdfimages -p -j '+file+' /var/www/html/parser/uploads/tempimg', shell=True, stderr=None)



"""
Matching ppm images with pattern to convert them in png images
At the same time, dict_book is updated with the path of the png images
"""
import glob
import json
import uuid
import re
import os

def get_img_names_by_page_number():
    image_list = {}
    nb_images = 0
    ppm_images = glob.glob('/var/www/html/parser/uploads/tempimg*.*')
    for image in ppm_images:
        match = re.match( r'\/var/www/html/parser/uploads/tempimg\-(\d+)\-(\d+)\.[jpg|ppm|pbm]', image, re.M|re.I)
        if match:
            page_num = int(match.group(1)) - 1
            image_num = int(match.group(2))
            nb_images += 1
            if page_num not in image_list:
                image_list[page_num] = {}
            image_list[page_num].update({image_num:image})
    return image_list, nb_images

def rename_imgs__update_dict(image_list, dict_book, image_folder):
    image_num = 1
    for page in image_list.iterkeys():
        for image in image_list[page].iterkeys():
            image_num += 1
            if 'images' not in dict_book['pages'][page]:
                dict_book['pages'][page].update({'images':[]})
            if "jpg" in image_list[page][image]:
                image_name = "%s_p%d.jpg" % (uuid.uuid1(), page)
                dict_book['pages'][page]['images'].append(image_name)
                subprocess.call('mv %s %s' % (image_list[page][image], image_folder+image_name), shell=True, stderr=sys.stdout)
            elif "ppm" in image_list[page][image] or "pbm" in image_list[page][image]:
                image_name = "%s_p%d.png" % (uuid.uuid1(), page)
                dict_book['pages'][page]['images'].append(image_name)
                subprocess.call('/usr/bin/convert %s %s'%(image_list[page][image], image_folder+image_name), shell=True, stderr=sys.stdout)
                os.remove(image_list[page][image])
    return dict_book

def get_images_update_dict(dict_book, image_folder):
    image_list, nb_images = get_img_names_by_page_number()
    dict_book = rename_imgs__update_dict(image_list, dict_book, image_folder)
    return dict_book


def run(pdf_file, image_folder):
    dict_book = text_to_dict(pdf_file)
    extract_images(pdf_file)
    dict_book = get_images_update_dict(dict_book, image_folder)
    return json.dumps(dict_book)


if __name__ == '__main__':
    import sys
    if len(sys.argv) == 3:
        print run(pdf_file=sys.argv[1], image_folder=sys.argv[2])
    else:
        print "usage: %s pdf_file_path generated_images_path/ (eg: python %s book.pdf './images/')" % (sys.argv[0], sys.argv[0])
